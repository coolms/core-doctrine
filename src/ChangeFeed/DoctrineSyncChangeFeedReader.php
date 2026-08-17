<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\ChangeFeed;

use CoolMS\Core\ChangeFeed\SyncChange;
use CoolMS\Core\ChangeFeed\SyncChangeDelta;
use CoolMS\Core\ChangeFeed\SyncChangeFeedReaderInterface;
use CoolMS\Core\ChangeFeed\SyncChangeOp;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function max;
use function min;
use function sprintf;

/**
 * DBAL projection of the controller→edge change-feed — the Doctrine
 * adapter behind {@see SyncChangeFeedReaderInterface}. Reads `coolms_sync_changes` after a
 * `seq` cursor as a plain ordered window; the port's docblock carries the cursor design +
 * the deferred commit-ordering boundary. Kept a pure window so the delivery machinery has
 * one simple feed to page over.
 *
 * `$limit` is clamped to `[1, MAX_LIMIT]` and inlined into the SQL (a validated int, no
 * injection surface); only the cursor is bound.
 */
#[AsAlias(SyncChangeFeedReaderInterface::class)]
final class DoctrineSyncChangeFeedReader implements SyncChangeFeedReaderInterface
{
    private const int MAX_LIMIT = 1000;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function readAfter(int $afterSeq, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $sql = sprintf(
            'SELECT seq, table_name, row_id, op, recorded_at FROM %s WHERE seq > :after ORDER BY seq ASC LIMIT %d',
            SyncChange::TABLE,
            $limit,
        );

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            ['after' => $afterSeq],
            ['after' => ParameterType::INTEGER],
        );

        $deltas = [];
        foreach ($rows as $row) {
            $deltas[] = new SyncChangeDelta(
                (int) $row['seq'],
                (string) $row['table_name'],
                (string) $row['row_id'],
                SyncChangeOp::from((string) $row['op']),
                new DateTimeImmutable((string) $row['recorded_at']),
            );
        }

        return $deltas;
    }
}
