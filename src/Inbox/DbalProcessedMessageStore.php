<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Inbox;

use CoolMS\Core\Inbox\ProcessedMessageStoreInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Uid\Uuid;

use function is_string;

/**
 * DBAL-backed {@see ProcessedMessageStoreInterface} (F7). `firstSeen` is a single
 * atomic `INSERT ... ON CONFLICT DO NOTHING` — race-safe AND, crucially, it never
 * throws a unique-violation: on Postgres a caught unique violation would POISON
 * the caller's transaction (every later statement errors until rollback), so the
 * try/catch idiom is unusable inside a consumer's tx. `affected = 1` means we
 * inserted (first time → proceed); `0` means a row already existed (replay → skip).
 *
 * `#[Autoconfigure(public: true)]` so it survives the glob + stays resolvable
 * before the first idempotent consumer wires the port (which prunes-until-then).
 */
#[Autoconfigure(public: true)]
final readonly class DbalProcessedMessageStore implements ProcessedMessageStoreInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function firstSeen(string $consumer, string $messageId): bool
    {
        $affected = $this->connection->executeStatement(
            'INSERT INTO coolms_inbox (id, consumer, message_id, processed_at) VALUES (?, ?, ?, now()) '
            . 'ON CONFLICT (consumer, message_id) DO NOTHING',
            [Uuid::v7()->toRfc4122(), $consumer, $messageId],
        );

        return 1 === $affected;
    }

    public function firstSeenRef(string $consumer, string $messageId, string $ref): ?string
    {
        $affected = $this->connection->executeStatement(
            'INSERT INTO coolms_inbox (id, consumer, message_id, ref, processed_at) VALUES (?, ?, ?, ?, now()) '
            . 'ON CONFLICT (consumer, message_id) DO NOTHING',
            [Uuid::v7()->toRfc4122(), $consumer, $messageId, $ref],
        );

        if (1 === $affected) {
            return null; // We claimed it — first sighting, caller proceeds.
        }

        // Replay: somebody claimed it first. Hand back THEIR ref so the caller
        // maps onto the same aggregate. Read-after-conflict is safe here because
        // the conflicting row is already committed (we lost the race to it) —
        // there is no window where the row exists but its ref is unreadable.
        $existing = $this->connection->fetchOne(
            'SELECT ref FROM coolms_inbox WHERE consumer = ? AND message_id = ?',
            [$consumer, $messageId],
        );

        return is_string($existing) && '' !== $existing ? $existing : null;
    }

    public function hasProcessed(string $consumer, string $messageId): bool
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM coolms_inbox WHERE consumer = ? AND message_id = ?',
            [$consumer, $messageId],
        );

        return $count > 0;
    }

    public function refFor(string $consumer, string $messageId): ?string
    {
        $ref = $this->connection->fetchOne(
            'SELECT ref FROM coolms_inbox WHERE consumer = ? AND message_id = ?',
            [$consumer, $messageId],
        );

        return is_string($ref) && '' !== $ref ? $ref : null;
    }

    public function deleteProcessedOlderThan(DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM coolms_inbox WHERE processed_at < ?',
            [$cutoff],
            [Types::DATETIMETZ_IMMUTABLE],
        );
    }

    public function countProcessedOlderThan(DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM coolms_inbox WHERE processed_at < ?',
            [$cutoff],
            [Types::DATETIMETZ_IMMUTABLE],
        );
    }
}
