<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Health;

use CoolMS\Core\Doctrine\Health\OutboxRelayProbe;
use CoolMS\Core\Outbox\OutboxBacklog;
use CoolMS\Core\Outbox\OutboxBacklogInterface;
use CoolMS\Core\Outbox\RelayHeartbeat;
use CoolMS\Core\Outbox\RelayHeartbeatInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

/**
 * Without a heartbeat the relay records nothing of itself, so the probe infers
 * from the backlog -- and the one thing it must never do is call an empty
 * queue "ok". A dead relay over an idle producer looks exactly like a live
 * one; that case is `unknown`, not counted, with the last published row as
 * the activity it CAN report.
 *
 * With a heartbeat wired the same empty queue has an answer: a beat inside
 * the window is a relay that ran, none or a stale one is a relay that
 * stopped, and a fresh beat over rows past the grace period is a relay that
 * runs and does not publish.
 */
final class OutboxRelayProbeTest extends TestCase
{
    #[Test]
    public function aBacklogPastTheGracePeriodIsSilentAndCounts(): void
    {
        $oldest = new DateTimeImmutable('2026-09-21 03:00:00');
        $probe = new OutboxRelayProbe(
            $this->backlog(new OutboxBacklog(7, 3, $oldest)),
            $this->connection('2026-09-21 02:59:00'),
        );

        $state = $probe->check();

        self::assertSame('DOWN', $state->status());
        self::assertTrue($state->isFailing());
        self::assertStringContainsString('3 row(s) unpublished past the grace period', $state->detail);
        self::assertStringContainsString('7 unpublished in all', $state->detail);
        self::assertSame('2026-09-21 02:59:00', $state->lastActivityAt?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function aBacklogInsideTheGracePeriodIsDraining(): void
    {
        $probe = new OutboxRelayProbe(
            $this->backlog(new OutboxBacklog(4, 0, new DateTimeImmutable('-30 seconds'))),
            $this->connection('2026-09-21 04:25:30'),
        );

        $state = $probe->check();

        self::assertSame('ok', $state->status());
        self::assertFalse($state->isFailing());
        self::assertStringContainsString('4 unpublished, none older than the grace period -- draining', $state->detail);
    }

    #[Test]
    public function anEmptyQueueIsUnknownNeverOk(): void
    {
        $probe = new OutboxRelayProbe(
            $this->backlog(new OutboxBacklog(0, 0, null)),
            $this->connection('2026-09-21 04:25:30'),
        );

        $state = $probe->check();

        self::assertSame('unknown', $state->status());
        self::assertNotSame('ok', $state->status(), 'an empty queue proves no consumer');
        self::assertFalse($state->isFailing(), 'an idle producer is not a dead relay');
        self::assertStringContainsString('cannot tell', $state->detail);
        self::assertStringContainsString('published at 2026-09-21T04:25:30', $state->detail);
        self::assertSame('2026-09-21 04:25:30', $state->lastActivityAt?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function anEmptyOutboxThatNeverPublishedSaysSo(): void
    {
        $probe = new OutboxRelayProbe(
            $this->backlog(new OutboxBacklog(0, 0, null)),
            $this->connection(null),
        );

        $state = $probe->check();

        self::assertSame('unknown', $state->status());
        self::assertStringContainsString('published never (the outbox is empty)', $state->detail);
        self::assertNull($state->lastActivityAt);
    }

    #[Test]
    public function anOutboxThatCannotBeReadIsSilentWithTheReason(): void
    {
        $backlog = new class implements OutboxBacklogInterface {
            public function unpublishedBacklog(DateTimeImmutable $olderThan): OutboxBacklog
            {
                throw new RuntimeException('relation "coolms_outbox" does not exist');
            }
        };
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');

        $state = new OutboxRelayProbe($backlog, $connection)->check();

        self::assertSame('DOWN', $state->status());
        self::assertTrue($state->isFailing());
        self::assertStringContainsString('the outbox could not be read: RuntimeException: relation "coolms_outbox" does not exist', $state->detail);
    }

    #[Test]
    public function theAskNamesTheGracePeriodSoARowCanBeRepeatedByHand(): void
    {
        $probe = new OutboxRelayProbe($this->backlog(new OutboxBacklog(0, 0, null)), $this->connection(null));

        self::assertSame(
            'rows in the outbox unpublished for more than 600s, and max(published_at) (the relay records nothing of itself)',
            $probe->check()->ask,
        );
    }

    #[Test]
    public function withAHeartbeatAFreshBeatOverAnEmptyQueueIsOk(): void
    {
        $clock = new MockClock('2026-09-21 09:00:30');
        $probe = new OutboxRelayProbe(
            $this->backlog(new OutboxBacklog(0, 0, null)),
            $this->connection('2026-09-21 04:25:30'),
            $this->heartbeat(new RelayHeartbeat(new DateTimeImmutable('2026-09-21 09:00:24'), 100, 0)),
            $clock,
        );

        $state = $probe->check();

        self::assertSame('ok', $state->status());
        self::assertFalse($state->isFailing());
        self::assertSame('heartbeat 6s ago: a batch of 100 asked, 0 published; 0 unpublished now', $state->detail);
        self::assertSame(
            '2026-09-21 09:00:24',
            $state->lastActivityAt?->format('Y-m-d H:i:s'),
            'the beat, not the last row',
        );
        self::assertStringContainsString('last heartbeat (within 60s)', $state->ask);
    }

    #[Test]
    public function withAHeartbeatABeatOlderThanTheWindowIsAStoppedRelay(): void
    {
        $probe = new OutboxRelayProbe(
            $this->backlog(new OutboxBacklog(0, 0, null)),
            $this->connection(null),
            $this->heartbeat(new RelayHeartbeat(new DateTimeImmutable('2026-09-21 08:58:00'), 100, 3)),
            new MockClock('2026-09-21 09:00:30'),
        );

        $state = $probe->check();

        self::assertSame('DOWN', $state->status());
        self::assertTrue($state->isFailing(), 'an empty queue no longer excuses a relay that stopped beating');
        self::assertSame(
            'last heartbeat 150s ago at 2026-09-21T08:58:00+00:00 -- the relay has stopped',
            $state->detail,
        );
        self::assertSame('2026-09-21 08:58:00', $state->lastActivityAt?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function withAHeartbeatNoBeatAtAllIsARelayThatNeverRan(): void
    {
        $probe = new OutboxRelayProbe(
            $this->backlog(new OutboxBacklog(2, 0, new DateTimeImmutable('2026-09-21 09:00:00'))),
            $this->connection('2026-09-21 04:25:30'),
            $this->heartbeat(null),
            new MockClock('2026-09-21 09:00:30'),
        );

        $state = $probe->check();

        self::assertSame('DOWN', $state->status());
        self::assertStringContainsString('no heartbeat recorded -- the relay has not run', $state->detail);
        self::assertSame(
            '2026-09-21 04:25:30',
            $state->lastActivityAt?->format('Y-m-d H:i:s'),
            'the last row is all there is to report',
        );
    }

    #[Test]
    public function withAHeartbeatAFreshBeatOverStaleRowsIsARelayThatDoesNotPublish(): void
    {
        $probe = new OutboxRelayProbe(
            $this->backlog(new OutboxBacklog(7, 3, new DateTimeImmutable('2026-09-21 08:00:00'))),
            $this->connection('2026-09-21 04:25:30'),
            $this->heartbeat(new RelayHeartbeat(new DateTimeImmutable('2026-09-21 09:00:27'), 100, 0)),
            new MockClock('2026-09-21 09:00:30'),
        );

        $state = $probe->check();

        self::assertSame('DOWN', $state->status());
        self::assertStringContainsString(
            'beating (3s ago) but 3 row(s) unpublished past the grace period',
            $state->detail,
        );
        self::assertStringContainsString('the relay runs and does not publish', $state->detail);
    }

    private function heartbeat(?RelayHeartbeat $last): RelayHeartbeatInterface
    {
        return new class($last) implements RelayHeartbeatInterface {
            public function __construct(private readonly ?RelayHeartbeat $last)
            {
            }

            public function beat(RelayHeartbeat $heartbeat): void
            {
            }

            public function last(): ?RelayHeartbeat
            {
                return $this->last;
            }
        };
    }

    private function backlog(OutboxBacklog $result): OutboxBacklogInterface
    {
        return new class($result) implements OutboxBacklogInterface {
            public function __construct(private readonly OutboxBacklog $result)
            {
            }

            public function unpublishedBacklog(DateTimeImmutable $olderThan): OutboxBacklog
            {
                return $this->result;
            }
        };
    }

    private function connection(?string $maxPublishedAt): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')
            ->with('SELECT MAX(published_at) FROM coolms_outbox')
            ->willReturn($maxPublishedAt);

        return $connection;
    }
}
