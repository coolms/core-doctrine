<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Health;

use CoolMS\Core\Health\DependencyState;
use CoolMS\Core\Health\LivenessProbeInterface;
use CoolMS\Core\Outbox\OutboxBacklog;
use CoolMS\Core\Outbox\OutboxBacklogInterface;
use CoolMS\Core\Outbox\RelayHeartbeat;
use CoolMS\Core\Outbox\RelayHeartbeatInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Throwable;

use function is_string;
use function sprintf;

/**
 * Is the outbox relay draining?
 *
 * With a heartbeat wired ({@see RelayHeartbeatInterface}), the relay records
 * every pass of its own, an empty one included, and the question has an
 * answer: a beat inside the window over a healthy backlog is ok; no beat, or
 * a beat older than the window, is a relay that stopped -- DOWN; a fresh beat
 * over rows past the grace period is a relay that runs and does not publish
 * -- DOWN, named as such. The window is the figure the relay's own status
 * command calls "should be zero while a relay runs".
 *
 * Without one, the relay leaves no record of ITSELF: a row it published
 * carries `published_at`, a row it has not reached carries nothing, and a
 * relay that stopped looks exactly like one with nothing to do. What can be
 * asked then is the backlog, through the platform's own port
 * ({@see OutboxBacklogInterface}):
 *
 *   - rows unpublished past the grace period: the relay is behind -- DOWN;
 *   - rows unpublished, none past it: the relay is draining -- ok;
 *   - no unpublished row at all: CANNOT TELL. An empty queue does not prove a
 *     consumer; a dead relay over an idle producer reads exactly like a live
 *     one. That case is reported as such -- `unknown`, never ok -- with the
 *     last time a row WAS published as the activity, read here because the
 *     port carries no last-published and this is the Doctrine package.
 */
final readonly class OutboxRelayProbe implements LivenessProbeInterface
{
    private const int GRACE_SECONDS = 600;

    /** A relay passes every few seconds; a minute without a beat is a stopped relay. */
    public const int BEAT_WINDOW_SECONDS = 60;

    public function __construct(
        private OutboxBacklogInterface $backlog,
        private Connection $connection,
        private ?RelayHeartbeatInterface $heartbeat = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function check(): DependencyState
    {
        $now = $this->clock?->now() ?? new DateTimeImmutable();
        $olderThan = $now->modify(sprintf('-%d seconds', self::GRACE_SECONDS));
        $ask = null === $this->heartbeat
            ? sprintf(
                'rows in the outbox unpublished for more than %ds, and max(published_at) (the relay records nothing of itself)',
                self::GRACE_SECONDS,
            )
            : sprintf(
                'the relay\'s last heartbeat (within %ds), and rows in the outbox unpublished for more than %ds',
                self::BEAT_WINDOW_SECONDS,
                self::GRACE_SECONDS,
            );
        try {
            $backlog = $this->backlog->unpublishedBacklog($olderThan);
            $lastPublished = $this->lastPublishedAt();
            $beat = $this->heartbeat?->last();
        } catch (Throwable $e) {
            return DependencyState::silent(
                'Outbox relay',
                $ask,
                sprintf('the outbox could not be read: %s: %s', $e::class, $e->getMessage()),
            );
        }
        if (null !== $this->heartbeat) {
            return $this->fromHeartbeat($ask, $now, $beat, $backlog, $lastPublished);
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

    private function fromHeartbeat(
        string $ask,
        DateTimeImmutable $now,
        ?RelayHeartbeat $beat,
        OutboxBacklog $backlog,
        ?DateTimeImmutable $lastPublished,
    ): DependencyState {
        if (null === $beat) {
            return DependencyState::silent(
                'Outbox relay',
                $ask,
                'no heartbeat recorded -- the relay has not run, or beats in a pool this process does not read',
                lastActivityAt: $lastPublished,
            );
        }
        $age = $beat->ageInSeconds($now);
        if ($age > self::BEAT_WINDOW_SECONDS) {
            return DependencyState::silent(
                'Outbox relay',
                $ask,
                sprintf('last heartbeat %ds ago at %s -- the relay has stopped', $age, $beat->at->format(DATE_ATOM)),
                lastActivityAt: $beat->at,
            );
        }
        if (!$backlog->isHealthy()) {
            return DependencyState::silent(
                'Outbox relay',
                $ask,
                sprintf(
                    'beating (%ds ago) but %d row(s) unpublished past the grace period, the oldest since %s -- the relay runs and does not publish',
                    $age,
                    $backlog->staleUnpublished,
                    $backlog->oldestUnpublishedAt?->format(DATE_ATOM) ?? 'an unknown time',
                ),
                lastActivityAt: $beat->at,
            );
        }

        return DependencyState::answered(
            'Outbox relay',
            $ask,
            sprintf(
                'heartbeat %ds ago: a batch of %d asked, %d published; %d unpublished now',
                $age,
                $beat->batch,
                $beat->published,
                $backlog->unpublished,
            ),
            lastActivityAt: $beat->at,
        );
    }

    private function lastPublishedAt(): ?DateTimeImmutable
    {
        $value = $this->connection->fetchOne('SELECT MAX(published_at) FROM coolms_outbox');

        return is_string($value) && '' !== $value ? new DateTimeImmutable($value) : null;
    }
}
