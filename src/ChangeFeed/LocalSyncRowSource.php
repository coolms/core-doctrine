<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\ChangeFeed;

use CoolMS\Core\Backup\SyncedTableSetInterface;
use CoolMS\Core\Backup\TableBackupPortInterface;
use CoolMS\Core\ChangeFeed\SyncRowSourceInterface;

/**
 * The same-host {@see SyncRowSourceInterface}: hydrates changed rows straight from the
 * LOCAL DB via {@see TableBackupPortInterface::dumpRowsByIds}. Used when a host replays
 * its own change feed. The EDGE uses a REMOTE (HTTP) source instead — an edge replaying
 * the controller's feed against its OWN DB would find nothing to copy locally.
 *
 * The key column is asked of {@see SyncedTableSetInterface::ownerColumnFor()} rather than
 * assumed to be `id`: for an owned-collection table the feed's `row_id` names the
 * OWNER, so `$ids` are owner ids and the fetch returns that owner's whole current set —
 * which is exactly what the applier's set-replace needs.
 *
 * No `#[AsAlias]`: {@see \CoolMS\CoreModule\ChangeFeed\SyncChangeApplier} takes the
 * source per-call, so nothing autowires the interface (and a Local default would be the
 * wrong choice on an edge).
 */
final readonly class LocalSyncRowSource implements SyncRowSourceInterface
{
    public function __construct(
        private TableBackupPortInterface $tables,
        private SyncedTableSetInterface $registry,
    ) {
    }

    public function fetchRows(string $table, array $ids): array
    {
        return $this->tables->dumpRowsByIds($table, $this->registry->ownerColumnFor($table) ?? 'id', $ids);
    }
}
