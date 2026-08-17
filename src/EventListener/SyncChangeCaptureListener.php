<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\EventListener;

use CoolMS\Core\ChangeFeed\SyncChange;
use CoolMS\Core\ChangeFeed\SyncChangeOp;
use CoolMS\Core\ChangeFeed\SyncChangesCaptured;
use CoolMS\CoreModule\Backup\BackupTableRegistry;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;
use function is_scalar;
use function reset;

/**
 * Captures every committed row change to a SYNCED table into the controller→edge
 * change-feed — the durable CDC log a push relay / pull edge
 * later replays. Generalises the realtime-ping pattern of the Calendar live
 * dispatcher into ONE platform listener with a DURABLE, transactional
 * sink.
 *
 * **Same-transaction durability (the load-bearing property).** Doctrine dispatches
 * `onFlush` BEFORE it opens the flush's DB transaction, so persisting a {@see SyncChange}
 * here + calling {@see UnitOfWork::computeChangeSet()} enrols it into the
 * SAME transaction as the domain write — the change record and the row it describes
 * commit together or neither does (never a committed write without its change record,
 * which would silently drift an edge). We deliberately do NOT swallow failures: if the
 * feed insert fails the whole flush rolls back (correct for CDC), unlike a best-effort
 * realtime ping.
 *
 * **Capture scope = the backup contributors' tables** ({@see BackupTableRegistry}, B.2.1),
 * equal to what backup EXPORTS -- but note this file claimed exactly that while
 * it was FALSE (`coolms_identity_user_groups` was exported and uncapturable), so
 * treat the equality as a property to re-check when adding a table, not a given. Non-synced
 * tables (runtime, credential, the feed's own table) are skipped.
 *
 * **Two capture channels, because a synced table is written in two shapes.**
 * INSERT + UPDATE both record `upsert` (the edge applies by idempotent upsert-by-UUID);
 * DELETE records `delete`.
 *  1. **Entity rows** — the scheduled-entity lists, keyed by the row's own single UUID.
 *  2. **Owned collections** — a ManyToMany JoinTable has a composite PK and NO
 *     entity, so it is invisible to (1) and unrepresentable in a one-UUID `row_id`. Such
 *     a table is captured keyed by its OWNER (`(user_groups, <user_id>, upsert)` = "this
 *     user's set changed, re-read it"), per the owning contributor's
 *     {@see \CoolMS\Core\Backup\SyncsAsOwnedCollectionInterface} declaration. **Do NOT
 *     "improve" this into per-membership deltas via `getSnapshot()`:** the vendored
 *     `PersistentCollection::clear()` calls `takeSnapshot()` synchronously right after
 *     emptying itself, so by the time `onFlush` runs the removed members are GONE — the
 *     one shape that cannot be recovered is the one that matters most.
 *
 * **The owner-deletion subtlety, verified in vendored Doctrine, not assumed.**
 * `scheduleCollectionDeletion()` is called from exactly ONE place —
 * `PersistentCollection::clear()`. Deleting the OWNING ENTITY schedules no collection
 * event at all: `BasicEntityPersister::delete()` purges the join rows itself via
 * `deleteJoinTableRecords()` before deleting the row. So capture must ALSO fire on owner
 * deletion, or an edge keeps orphan membership rows and then hits the `NO ACTION` user FK
 * when the delete arrives — loud, but broken. Both cases emit the SAME delta (re-read the
 * owner's set); after the owner is gone the set reads empty and the rows are purged.
 *
 * **STILL NOT captured (documented gaps):** the two `coolms_vfs_nodes` UoW-bypassing
 * writes are covered by {@see \CoolMS\Core\ChangeFeed\SyncChangeRecorderInterface}
 * instead; VFS blob BYTES are not a feed gap at all but a missing channel.
 *
 * **`postFlush` announcement (B.2.5f).** Having captured, we tell the platform so —
 * {@see SyncChangesCaptured} on `postFlush`, which Doctrine fires AFTER the commit
 * (the live-ping precedent that preceded it). Two properties make this the right
 * seam and not the outbox: the announcement must never be visible before the rows it
 * announces (an edge that pulls on a nudge for an uncommitted write reads nothing and
 * would sit on a stale cursor), and losing one costs only latency. Core stops at the
 * FACT — the sync module turns it into a fleet nudge.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class SyncChangeCaptureListener
{
    /**
     * Set by `onFlush` when this flush captured ≥1 change, consumed by `postFlush`.
     * Reset at the TOP of every `onFlush` so a flush that throws between the two (its
     * `postFlush` never runs) cannot leak a stale announcement into the next flush.
     * A nested flush can still consume the outer flush's flag; the cost is one nudge
     * early or late — never a lost row, since the feed itself is what edges read.
     */
    private bool $capturedInFlush = false;

    public function __construct(
        private readonly BackupTableRegistry $syncedTables,
        private readonly ClockInterface $clock,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->capturedInFlush = false;

        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        // Resolve every change to a (table, rowId, op) triple BEFORE persisting our own
        // rows — persist()+computeChangeSet() below mutates the insertion list we iterate.
        // Keyed to de-duplicate: one flush that both changes a user's groups AND deletes
        // the user would otherwise emit the same owner delta twice.
        /** @var array<string, array{0: string, 1: string, 2: SyncChangeOp}> $pending */
        $pending = [];
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->collectEntity($em, $entity, SyncChangeOp::Upsert, $pending);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->collectEntity($em, $entity, SyncChangeOp::Upsert, $pending);
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->collectEntity($em, $entity, SyncChangeOp::Delete, $pending);
            // Doctrine purges this entity's join rows inside its own delete, with no
            // collection event — see the class docblock. Nothing else would capture it.
            $this->collectOwnedCollectionsOf($em, $entity, $pending);
        }
        // Collection channel: add / remove / clear on a synced JoinTable.
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            $this->collectCollection($em, $collection, $pending);
        }
        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            $this->collectCollection($em, $collection, $pending);
        }

        if ([] === $pending) {
            return;
        }

        $now = $this->clock->now();
        $changeMeta = $em->getClassMetadata(SyncChange::class);

        foreach ($pending as [$table, $rowId, $op]) {
            $change = new SyncChange($table, $rowId, $op, $now);
            $em->persist($change);
            $uow->computeChangeSet($changeMeta, $change);
            $this->capturedInFlush = true;
        }
    }

    /**
     * POST-COMMIT: the captured rows are now readable by anyone, so announcing is safe.
     * One announcement per flush, not per row — the fact is "there is new work in the
     * feed", and a listener that reacts by nudging cares about neither which rows nor
     * how many.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->capturedInFlush) {
            return;
        }

        $this->capturedInFlush = false;
        $this->events->dispatch(new SyncChangesCaptured());
    }

    /**
     * Channel 1 — an entity row, keyed by its own id.
     *
     * @param array<string, array{0: string, 1: string, 2: SyncChangeOp}> $pending
     */
    private function collectEntity(
        EntityManagerInterface $em,
        object $entity,
        SyncChangeOp $op,
        array &$pending,
    ): void {
        if ($entity instanceof SyncChange) {
            return; // never capture the feed itself (it is not a synced table anyway)
        }

        $meta = $em->getClassMetadata($entity::class);
        $table = $meta->getTableName();
        if (!$this->syncedTables->covers($table)) {
            return;
        }

        $rowId = $this->rowId($meta, $entity);
        if (null === $rowId) {
            return;
        }

        $this->add($table, $rowId, $op, $pending);
    }

    /**
     * Channel 2a — the owning entity is being DELETED, so every synced JoinTable it owns
     * loses its rows silently (no collection event). Emit the owner delta so the edge
     * re-reads (and empties) the set BEFORE the owner's own delete lands.
     *
     * @param array<string, array{0: string, 1: string, 2: SyncChangeOp}> $pending
     */
    private function collectOwnedCollectionsOf(
        EntityManagerInterface $em,
        object $entity,
        array &$pending,
    ): void {
        $meta = $em->getClassMetadata($entity::class);
        $rowId = $this->rowId($meta, $entity);
        if (null === $rowId) {
            return;
        }

        foreach ($meta->associationMappings as $mapping) {
            if (!$mapping instanceof ManyToManyOwningSideMapping) {
                continue;
            }
            $this->addOwnedCollection($mapping->joinTable->name, $rowId, $pending);
        }
    }

    /**
     * Channel 2b — a JoinTable collection was added to / removed from / cleared.
     *
     * Owning side only: Doctrine writes the join table from the owning side alone, so an
     * inverse-side collection (`Group::$users`) is a no-op there and keying it by the
     * GROUP id would name a row that does not exist. `instanceof ManyToManyOwningSideMapping`
     * settles both questions at once and gives typed access to `joinTable`.
     *
     * @param PersistentCollection<array-key, object>                     $collection
     * @param array<string, array{0: string, 1: string, 2: SyncChangeOp}> $pending
     */
    private function collectCollection(
        EntityManagerInterface $em,
        PersistentCollection $collection,
        array &$pending,
    ): void {
        $mapping = $collection->getMapping();
        if (!$mapping instanceof ManyToManyOwningSideMapping) {
            return;
        }

        $owner = $collection->getOwner();
        if (null === $owner) {
            return;
        }

        $rowId = $this->rowId($em->getClassMetadata($owner::class), $owner);
        if (null === $rowId) {
            return;
        }

        $this->addOwnedCollection($mapping->joinTable->name, $rowId, $pending);
    }

    /**
     * Record "this owner's set in `$table` changed" — but only for a table BOTH synced and
     * declared as an owned collection. The declaration is what makes the delta readable:
     * without it the applier would treat the owner id as a row id and hydrate by `id`, a
     * column the join table does not have.
     *
     * @param array<string, array{0: string, 1: string, 2: SyncChangeOp}> $pending
     */
    private function addOwnedCollection(string $table, string $ownerId, array &$pending): void
    {
        if (!$this->syncedTables->covers($table) || null === $this->syncedTables->ownerColumnFor($table)) {
            return;
        }

        // Always `upsert`: the delta means "re-read this owner's set", and an emptied set
        // (cleared, or owner deleted) converges by hydrating zero rows — never a `delete`,
        // whose row_id the applier would read as a single row's id.
        $this->add($table, $ownerId, SyncChangeOp::Upsert, $pending);
    }

    /**
     * @param array<string, array{0: string, 1: string, 2: SyncChangeOp}> $pending
     */
    private function add(string $table, string $rowId, SyncChangeOp $op, array &$pending): void
    {
        $pending[$table . "\0" . $rowId . "\0" . $op->value] = [$table, $rowId, $op];
    }

    /**
     * The row's stable id as a string. Every synced ENTITY has a single UUID primary key;
     * a composite-key entity would yield null and be skipped (there are none). Note the
     * one composite-key TABLE, `coolms_identity_user_groups`, never reaches here as a row
     * — it is captured by its owner, whose id is a single UUID (channel 2).
     *
     * @param ClassMetadata<object> $meta
     */
    private function rowId(ClassMetadata $meta, object $entity): ?string
    {
        $ids = $meta->getIdentifierValues($entity);
        if (1 !== count($ids)) {
            return null;
        }

        $id = reset($ids);

        return match (true) {
            $id instanceof Uuid => $id->toRfc4122(),
            is_scalar($id) => (string) $id,
            default => null,
        };
    }
}
