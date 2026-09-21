<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Health;

use CoolMS\Core\Health\DependencyState;
use CoolMS\Core\Health\LivenessProbeInterface;
use CoolMS\Core\Outbox\OutboxBacklogInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Throwable;

use function is_string;
use function sprintf;

/**
 * Is the outbox relay draining?
 *
 * The relay leaves no record of ITSELF: a row it published carries
 * `published_at`, a row it has not reached carries nothing, and a relay that
 * stopped looks exactly like one with nothing to do. What can be asked is the
 * backlog, through the platform's own port ({@see OutboxBacklogInterface}):
 *
 *   - rows unpublished past the grace period: the relay is behind -- DOWN;
 *   - rows unpublished, none past it: the relay is draining -- ok;
 *   - no unpublished row at all: CANNOT TELL. An empty queue does not prove a
 *     consumer; a dead relay over an idle producer reads exactly like a live
 *     one. That case is reported as such -- `unknown`, never ok -- with the
 *     last time a row WAS published as the activity, read here because the
 *     port carries no last-published and this is the Doctrine package.
 *
 * A heartbeat written by the relay itself would turn the third case into a
 * question with an answer; until then the report says what it could not tell.
 */
final readonly class OutboxRelayProbe implements LivenessProbeInterface
{
    private const int GRACE_SECONDS = 600;

    public function __construct(
        private OutboxBacklogInterface $backlog,
        private Connection $connection,
    ) {
    }

    public function check(): DependencyState
    {
        $olderThan = new DateTimeImmutable(sprintf('-%d seconds', self::GRACE_SECONDS));
        $ask = sprintf(
            'rows in the outbox unpublished for more than %ds, and max(published_at) (the relay records nothing of itself)',
            self::GRACE_SECONDS,
        );
        try {
            $backlog = $this->backlog->unpublishedBacklog($olderThan);
            $lastPublished = $this->lastPublishedAt();
        } catch (Throwable $e) {
            return DependencyState::silent(
                'Outbox relay',
                $ask,
                sprintf('the outbox could not be read: %s: %s', $e::class, $e->getMessage()),
            );
        }
        if (!$backlog->isHealthy()) {
            return DependencyState::silent(
                'Outbox relay',
                $ask,
                sprintf(
                    '%d row(s) unpublished past the grace period, %d unpublished in all, the oldest since %s -- the relay has probably stopped',
                    $backlog->staleUnpublished,
                    $backlog->unpublished,
                    $backlog->oldestUnpublishedAt?->format(DATE_ATOM) ?? 'an unknown time',
                ),
                lastActivityAt: $lastPublished,
            );
        }
        if ($backlog->unpublished > 0) {
            return DependencyState::answered(
                'Outbox relay',
                $ask,
                sprintf('%d unpublished, none older than the grace period -- draining', $backlog->unpublished),
                lastActivityAt: $lastPublished,
            );
        }

        return DependencyState::inconclusive(
            'Outbox relay',
            $ask,
            sprintf(
                'cannot tell -- no unpublished row to watch the relay through; the last row was published %s',
                null === $lastPublished ? 'never (the outbox is empty)' : 'at ' . $lastPublished->format(DATE_ATOM),
            ),
            lastActivityAt: $lastPublished,
        );
    }

    private function lastPublishedAt(): ?DateTimeImmutable
    {
        $value = $this->connection->fetchOne('SELECT MAX(published_at) FROM coolms_outbox');

        return is_string($value) && '' !== $value ? new DateTimeImmutable($value) : null;
    }
}
