<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\ChangeFeed;

use CoolMS\Core\Backup\SyncedTableSetInterface;
use CoolMS\Core\ChangeFeed\SyncChange;
use CoolMS\Core\ChangeFeed\SyncChangeOp;
use CoolMS\Core\ChangeFeed\SyncChangeRecorderInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

use function count;

/**
 * The DBAL adapter behind {@see SyncChangeRecorderInterface} — Doctrine stays confined to
 * `Infrastructure\Doctrine\`, mirroring {@see DoctrineSyncChangeFeedReader}.
 *
 * **It INSERTs directly rather than persisting an entity, and that is the load-bearing
 * decision, not a shortcut.** Its callers write mid-`commit()` (`preUpdate`), where the
 * UnitOfWork has already computed its change sets — an `$em->persist()` there is a SILENT
 * no-op: no row, no error, green tests, drift in prod. This epic has now been bitten by
 * that shape twice -- an outbox appender inside `onFlush`, and a whole reverted
 * slice), so the port is deliberately built where it cannot happen. The trade is that
 * `seq` and defaults come from the DB, which is where they already came from.
 *
 * Atomicity comes free from the CALLER's transaction: at `preUpdate` Doctrine's commit
 * transaction is open, so this INSERT joins it and rolls back with the write it describes.
 * A caller outside any transaction gets its own autocommit — see the interface's contract.
 */
#[AsAlias(SyncChangeRecorderInterface::class)]
final readonly class DoctrineSyncChangeRecorder implements SyncChangeRecorderInterface
{
    public function __construct(
        private Connection $connection,
        private SyncedTableSetInterface $syncedTables,
        private ClockInterface $clock,
    ) {
    }

    public function recordUpserts(string $table, array $rowIds): int
    {
        if ([] === $rowIds || !$this->syncedTables->covers($table)) {
            return 0;
        }

        $now = $this->clock->now();
        foreach ($rowIds as $rowId) {
            $this->connection->insert(SyncChange::TABLE, [
                'id' => Uuid::v7()->toRfc4122(),
                'table_name' => $table,
                'row_id' => $rowId,
                'op' => SyncChangeOp::Upsert->value,
                // `seq` is GENERATED ALWAYS AS IDENTITY — never supplied here.
                'recorded_at' => $now->format('Y-m-d H:i:sP'),
            ]);
        }

        return count($rowIds);
    }
}
