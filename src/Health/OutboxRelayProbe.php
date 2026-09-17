<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Health;

use CoolMS\Core\Health\DependencyState;
use CoolMS\Core\Health\LivenessProbeInterface;
use CoolMS\Core\Outbox\OutboxBacklogInterface;
use DateTimeImmutable;
use Throwable;

use function sprintf;

/**
 * Is the outbox relay draining?
 *
 * The relay leaves no record of ITSELF: a row it published carries
 * `published_at`, a row it has not reached carries nothing, and a relay that
 * stopped looks exactly like one with nothing to do. The signal is therefore
 * the STALE backlog -- rows still unpublished after the grace period -- read
 * through the platform's own port ({@see OutboxBacklogInterface}, the one
 * indexed count a monitor may ask every minute). Zero means the relay kept
 * up or had nothing to do; the detail says which, with the counts, so the
 * reader is not told "healthy" where "idle" is all that was measured. The
 * oldest unpublished row's timestamp is the activity: it is the last moment
 * the relay is known to have been behind by.
 *
 * Inferred, not asked, and the report says so.
 */
final readonly class OutboxRelayProbe implements LivenessProbeInterface
{
    private const int GRACE_SECONDS = 600;

    public function __construct(private OutboxBacklogInterface $backlog)
    {
    }

    public function check(): DependencyState
    {
        $olderThan = new DateTimeImmutable(sprintf('-%d seconds', self::GRACE_SECONDS));
        $ask = sprintf(
            'rows in the outbox unpublished for more than %ds (the relay records nothing of itself)',
            self::GRACE_SECONDS,
        );

        try {
            $backlog = $this->backlog->unpublishedBacklog($olderThan);
        } catch (Throwable $e) {
            return DependencyState::silent(
                'Outbox relay',
                $ask,
                sprintf('the backlog could not be read: %s: %s', $e::class, $e->getMessage()),
            );
        }

        if ($backlog->isHealthy()) {
            return DependencyState::answered(
                'Outbox relay',
                $ask,
                0 === $backlog->unpublished
                    ? 'nothing unpublished -- idle or kept up, inferred'
                    : sprintf(
                        '%d unpublished, none older than the grace period -- draining, inferred',
                        $backlog->unpublished,
                    ),
                lastActivityAt: $backlog->oldestUnpublishedAt,
            );
        }

        return DependencyState::silent(
            'Outbox relay',
            $ask,
            sprintf(
                '%d row(s) unpublished past the grace period, %d in all, oldest since %s'
                    . ' -- the relay has probably stopped',
                $backlog->staleUnpublished,
                $backlog->unpublished,
                $backlog->oldestUnpublishedAt?->format(DATE_ATOM) ?? 'an unknown time',
            ),
            lastActivityAt: $backlog->oldestUnpublishedAt,
        );
    }
}
