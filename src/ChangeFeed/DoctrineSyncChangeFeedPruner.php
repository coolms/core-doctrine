<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\ChangeFeed;

use CoolMS\Core\ChangeFeed\SyncChange;
use CoolMS\Core\ChangeFeed\SyncChangeFeedPrunerInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * DBAL adapter for {@see SyncChangeFeedPrunerInterface} — Doctrine confined to
 * `Infrastructure\Doctrine\`, mirroring {@see DoctrineSyncChangeFeedReader}.
 *
 * DBAL is mandatory here, not stylistic: `seq` is `GENERATED ALWAYS AS IDENTITY` and
 * intentionally UNMAPPED on {@see SyncChange}, so no DQL can name it. The `seq <= ?`
 * predicate rides the unique index on `seq`; the `recorded_at <` predicate rides
 * `idx_sync_changes_recorded`.
 */
#[AsAlias(SyncChangeFeedPrunerInterface::class)]
final readonly class DoctrineSyncChangeFeedPruner implements SyncChangeFeedPrunerInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function deletePrunable(?int $ackedThroughSeq, DateTimeImmutable $recordedBefore): int
    {
        [$where, $params, $types] = $this->predicate($ackedThroughSeq, $recordedBefore);

        return (int) $this->connection->executeStatement(
            'DELETE FROM ' . SyncChange::TABLE . ' WHERE ' . $where,
            $params,
            $types,
        );
    }

    public function countPrunable(?int $ackedThroughSeq, DateTimeImmutable $recordedBefore): int
    {
        [$where, $params, $types] = $this->predicate($ackedThroughSeq, $recordedBefore);

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . SyncChange::TABLE . ' WHERE ' . $where,
            $params,
            $types,
        );
    }

    /**
     * The one predicate both methods share — so a count can never disagree with the
     * delete it previews.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ParameterType>}
     */
    private function predicate(?int $ackedThroughSeq, DateTimeImmutable $recordedBefore): array
    {
        $where = 'recorded_at < :before';
        $params = ['before' => $recordedBefore->format('Y-m-d H:i:sP')];
        $types = ['before' => ParameterType::STRING];

        if (null !== $ackedThroughSeq) {
            $where .= ' AND seq <= :seq';
            $params['seq'] = $ackedThroughSeq;
            $types['seq'] = ParameterType::INTEGER;
        }

        return [$where, $params, $types];
    }
}
