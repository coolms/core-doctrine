<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Transaction;

use CoolMS\Core\Transaction\FailurePathWriterInterface;
use CoolMS\Core\Transaction\UnitOfWorkOwnerInterface;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

use function assert;

/**
 * The failure-path writer on Doctrine: an entity manager of its own, on a connection of its own to the same database,
 * built from the default manager's configuration (its mappings, naming, DQL functions) and event manager -- so a
 * record is written exactly as the application writes it, but in a short transaction committed before the call
 * returns, never part of the unit of work around it.
 *
 * Nothing is connected or built until the first record (a request that refuses nothing costs nothing). The connection
 * is a plain DBAL connection, never the lazy-begin wrapper: no unit of work owns it.
 *
 * Only while a unit of work is OPEN does a record need a connection of its own. With none open -- the owners switched
 * off, or a write outside any owner -- the default manager already commits each write at once, so the record goes
 * through it, exactly as any other write: no second connection, and whatever wraps that connection (a test's rollback)
 * wraps the record too.
 */
final class DoctrineFailurePathWriter implements FailurePathWriterInterface
{
    private ?EntityManagerInterface $em = null;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ?UnitOfWorkOwnerInterface $owner = null,
    ) {
    }

    public function persist(object $entity): void
    {
        $this->write(static function (EntityManagerInterface $em) use ($entity): void {
            $em->persist($entity);
        });
    }

    /**
     * Runs $work with the failure-path manager, flushes and commits at once, and clears the manager -- or, with no
     * unit of work open, with the default manager in a transaction of its own. For writes a single persist() does not
     * express -- an UPDATE of rows the request's own manager also reads.
     *
     * @template T
     *
     * @param callable(EntityManagerInterface): T $work
     *
     * @return T
     */
    public function write(callable $work): mixed
    {
        if (!$this->unitOpen()) {
            $default = $this->default();

            // Not the manager's wrapInTransaction(): it CLOSES the manager when the work throws, and this manager is
            // the request's own -- a refused record would leave the rest of the request unable to write. The
            // connection's transaction rolls back and leaves the manager as it was.
            return $default->getConnection()->transactional(static function () use ($default, $work): mixed {
                $result = $work($default);
                $default->flush();

                return $result;
            });
        }
        $em = $this->em();
        try {
            return $em->wrapInTransaction($work);
        } finally {
            if ($em->isOpen()) {
                $em->clear();
            } else {
                $this->em = null; // a failed write closed it: the next record builds a fresh one
            }
        }
    }

    /**
     * Runs $work with a plain DBAL connection, each statement committed as it runs (autocommit): the writer's own
     * connection while a unit of work is open, the default one otherwise. For records written as statements -- a
     * counter's upsert, an increment retried after a unique violation -- whose semantics are the statement's, not a
     * transaction's.
     *
     * @template T
     *
     * @param callable(Connection): T $work
     *
     * @return T
     */
    public function execute(callable $work): mixed
    {
        if (!$this->unitOpen()) {
            return $work($this->default()->getConnection());
        }

        return $work($this->em()->getConnection());
    }

    /** Whether the writer has connected yet -- false until the first record. */
    public function connected(): bool
    {
        return null !== $this->em && $this->em->getConnection()->isConnected();
    }

    /**
     * The default manager's event manager, as the EntityManager constructor takes it. ORM 3.6 returns an
     * EventManager from getEventManager(); ORM 3.7 widens the return to EventManagerInterface while the constructor
     * still takes EventManager|null -- the narrowing is needed on one and redundant on the other, so it lives here,
     * typed for both.
     */
    private static function eventManager(object $events): ?EventManager
    {
        return $events instanceof EventManager ? $events : null;
    }

    private function unitOpen(): bool
    {
        return null !== $this->owner && $this->owner->ownerActive();
    }

    private function default(): EntityManagerInterface
    {
        $default = $this->registry->getManager();
        assert($default instanceof EntityManagerInterface);

        return $default;
    }

    private function em(): EntityManagerInterface
    {
        if (null !== $this->em) {
            return $this->em;
        }
        $default = $this->registry->getManager();
        assert($default instanceof EntityManagerInterface);
        $params = $default->getConnection()->getParams();
        unset($params['wrapperClass']);
        $params['wrapperClass'] = Connection::class;
        $connection = DriverManager::getConnection($params, $default->getConnection()->getConfiguration());

        // The default manager's listeners, so a record is written as the application writes it.
        return $this->em = new EntityManager(
            $connection,
            $default->getConfiguration(),
            self::eventManager($default->getEventManager()),
        );
    }
}
