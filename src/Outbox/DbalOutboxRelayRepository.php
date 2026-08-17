<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Outbox;

use CoolMS\Core\Outbox\OutboxMessagePublished;
use CoolMS\Core\Outbox\OutboxRelayRepositoryInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

use function json_decode;
use function max;

/**
 * DBAL-backed {@see OutboxRelayRepositoryInterface}. The claim is a native
 * `FOR UPDATE SKIP LOCKED` SELECT (Postgres/MySQL) — the standard outbox-relay
 * primitive (also the M5 external-worker's fetch-and-lock pattern), which DQL
 * cannot express. Working at the DBAL level keeps the relay off the ORM identity
 * map and avoids hydrating entities just to publish + stamp them.
 */
final readonly class DbalOutboxRelayRepository implements OutboxRelayRepositoryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function claimUnpublished(int $limit): array
    {
        $limit = max(1, $limit);

        // LIMIT before FOR UPDATE; SKIP LOCKED so a concurrent relay worker's
        // in-flight rows are skipped rather than blocked on. $limit is an int,
        // so interpolation is injection-safe.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, type, payload, message_id, occurred_at FROM coolms_outbox '
            . 'WHERE published_at IS NULL ORDER BY created_at ASC LIMIT ' . $limit . ' FOR UPDATE SKIP LOCKED',
        );

        $messages = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $row['payload'], true) ?? [];
            $messages[] = new OutboxMessagePublished(
                outboxId: (string) $row['id'],
                type: (string) $row['type'],
                payload: $payload,
                messageId: null === $row['message_id'] ? null : (string) $row['message_id'],
                occurredAt: new DateTimeImmutable((string) $row['occurred_at']),
            );
        }

        return $messages;
    }

    public function markPublished(string $outboxId): void
    {
        $this->connection->executeStatement(
            'UPDATE coolms_outbox SET published_at = now() WHERE id = ?',
            [$outboxId],
        );
    }

    public function markFailed(string $outboxId): void
    {
        $this->connection->executeStatement(
            'UPDATE coolms_outbox SET attempts = attempts + 1 WHERE id = ?',
            [$outboxId],
        );
    }

    public function deletePublishedOlderThan(DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM coolms_outbox WHERE published_at IS NOT NULL AND published_at < ?',
            [$cutoff],
            [Types::DATETIMETZ_IMMUTABLE],
        );
    }

    public function countPublishedOlderThan(DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM coolms_outbox WHERE published_at IS NOT NULL AND published_at < ?',
            [$cutoff],
            [Types::DATETIMETZ_IMMUTABLE],
        );
    }
}
