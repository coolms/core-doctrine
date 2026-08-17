<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Outbox;

use CoolMS\Core\Outbox\OutboxAppenderInterface;
use CoolMS\Core\Outbox\OutboxMessage;
use CoolMS\Core\Outbox\OutboxRecord;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Default {@see OutboxAppenderInterface}: persists the outbox row into the
 * caller's current Unit of Work WITHOUT flushing, so it commits atomically with
 * the producer's domain change -- the transactional-outbox guarantee.
 *
 * `#[Autoconfigure(public: true)]` so it survives the `App\` glob + stays
 * resolvable before any producer consumes the port (the port alias is pruned-as-
 * unused until the first producer migrates onto the outbox — by design), the same
 * trick the analytics sink uses.
 */
#[Autoconfigure(public: true)]
final readonly class PersistingOutboxAppender implements OutboxAppenderInterface
{
    public function __construct(
        private ManagerRegistry $registry,
        private ClockInterface $clock,
    ) {
    }

    public function append(OutboxMessage $message): void
    {
        $record = OutboxRecord::fromMessage($message, $this->clock->now());

        $manager = $this->registry->getManagerForClass(OutboxRecord::class);
        if (null === $manager) {
            throw new RuntimeException('No object manager is configured for the outbox; cannot append.');
        }

        // Persist only — no flush. The row joins the caller's transaction and is
        // committed when the producer commits its domain change.
        $manager->persist($record);
    }
}
