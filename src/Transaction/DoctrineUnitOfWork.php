<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Transaction;

use CoolMS\Core\Transaction\UnitOfWorkException;
use CoolMS\Core\Transaction\UnitOfWorkInterface;
use CoolMS\Core\Transaction\UnitOfWorkOwnerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function count;
use function max;

/**
 * The Doctrine unit of work, through ManagerRegistry: the default manager and its connection. Both contracts, one
 * object: application code is handed it as {@see UnitOfWorkInterface}, the three owners as
 * {@see UnitOfWorkOwnerInterface}.
 *
 * Opening a unit touches no connection. When the connection is a {@see LazyBeginConnection} the unit arms it, and the
 * transaction begins before the first write or lock -- the ORM's flush transaction and every later begin then nest
 * inside it as savepoints. On any other connection the unit begins at once (the behaviour before lazy begin).
 *
 * Wire {@see preFlush()} and {@see postFlush()} as the manager's preFlush / postFlush listeners: a commit asked for while
 * a flush runs is refused (a commit inside a flush corrupts the flush's own bookkeeping).
 */
final class DoctrineUnitOfWork implements UnitOfWorkInterface, UnitOfWorkOwnerInterface
{
    private bool $open = false;

    /** The nesting level the unit's transaction began at, or null while it has not begun. */
    private ?int $base = null;

    private int $flushing = 0;

    /** @var list<callable(): void> */
    private array $effects = [];

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function begin(): void
    {
        if ($this->open) {
            throw new UnitOfWorkException('A unit of work is already open: a unit has one owner.');
        }
        $this->open = true;
        $this->base = null;
        $this->effects = [];
        $connection = $this->connection();
        if ($connection instanceof LazyBeginConnection) {
            $connection->armBeforeFirstWrite(fn () => $this->beginNow());
        } else {
            $this->beginNow();
        }
    }

    public function commit(): void
    {
        if (!$this->open) {
            throw new UnitOfWorkException('No unit of work is open to commit.');
        }
        if ($this->flushing > 0) {
            throw new UnitOfWorkException('A unit of work cannot commit while a flush is running.');
        }
        if (!$this->isUsable()) {
            $this->discard();

            throw new UnitOfWorkException('The unit of work is not usable (a closed manager, or rollback-only): discarded, never committed.');
        }
        try {
            $this->em()->flush();
            if (null !== $this->base) {
                $this->connection()->commit();
            }
        } catch (Throwable $e) {
            $this->discard();

            throw new UnitOfWorkException('The unit of work failed to commit and was discarded: ' . $e->getMessage(), 0, $e);
        }
        $effects = $this->effects;
        $this->close();
        foreach ($effects as $effect) {
            try {
                $effect();
            } catch (Throwable $e) {
                // Best-effort by contract: a failed effect is logged, never undoes the commit.
                $this->logger->error('An after-commit effect failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            }
        }
    }

    public function discard(): void
    {
        if (!$this->open) {
            return;
        }
        $base = $this->base;
        $this->close();
        $connection = $this->connection();
        try {
            // Every level the unit began -- its own and any savepoint a failure left open -- is rolled back.
            while (null !== $base && $connection->getTransactionNestingLevel() > $base) {
                $connection->rollBack();
            }
        } catch (Throwable $e) {
            $this->logger->error('The unit of work failed to roll back', ['message' => $e->getMessage()]);
        }
        $em = $this->em();
        if ($em->isOpen()) {
            $em->clear();
        } else {
            $this->registry->resetManager();
        }
    }

    public function isUsable(): bool
    {
        $em = $this->em();
        $connection = $em->getConnection();

        return $em->isOpen() && !($connection->isTransactionActive() && $connection->isRollbackOnly());
    }

    public function ownerActive(): bool
    {
        return $this->open;
    }

    public function transactional(callable $work): mixed
    {
        if ($this->open) {
            return $this->em()->wrapInTransaction($work);
        }
        // Outside any unit (a stream's callback, terminate, a daemon's event): a short unit of its own.
        $this->begin();
        try {
            $result = $this->em()->wrapInTransaction($work);
        } catch (Throwable $e) {
            $this->discard();

            throw $e;
        }
        $this->commit();

        return $result;
    }

    public function afterCommit(callable $effect): void
    {
        if ($this->open) {
            $this->effects[] = $effect;

            return;
        }
        $effect();
    }

    /**
     * The manager's preFlush listener. Only the default manager's flushes count: another manager on the same event
     * manager (the failure-path writer's) flushes outside the unit.
     */
    public function preFlush(?PreFlushEventArgs $args = null): void
    {
        if (null === $args || $args->getObjectManager() === $this->registry->getManager()) {
            ++$this->flushing;
        }
    }

    /** The manager's postFlush listener; the default manager's only, as preFlush. */
    public function postFlush(?PostFlushEventArgs $args = null): void
    {
        if (null === $args || $args->getObjectManager() === $this->registry->getManager()) {
            $this->flushing = max(0, $this->flushing - 1);
        }
    }

    /** How many after-commit effects the open unit holds; for owners' diagnostics. */
    public function pendingEffects(): int
    {
        return count($this->effects);
    }

    private function beginNow(): void
    {
        $connection = $this->connection();
        $this->base = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
    }

    private function close(): void
    {
        $this->open = false;
        $this->base = null;
        $this->effects = [];
        $this->flushing = 0;
        $connection = $this->connection();
        if ($connection instanceof LazyBeginConnection) {
            $connection->disarm();
        }
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->registry->getManager();
        if (!$em instanceof EntityManagerInterface) {
            throw new UnitOfWorkException('The default manager is not an ORM entity manager.');
        }

        return $em;
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }
}
