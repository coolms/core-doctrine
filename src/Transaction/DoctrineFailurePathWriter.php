<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Transaction;

use CoolMS\Core\Transaction\FailurePathWriterInterface;
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
 */
final class DoctrineFailurePathWriter implements FailurePathWriterInterface
{
    private ?EntityManagerInterface $em = null;

    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    public function persist(object $entity): void
    {
        $this->write(static function (EntityManagerInterface $em) use ($entity): void {
            $em->persist($entity);
        });
    }

    /**
     * Runs $work with the failure-path manager, flushes and commits at once, and clears the manager. For writes a
     * single persist() does not express -- an UPDATE of rows the request's own manager also reads.
     *
     * @template T
     *
     * @param callable(EntityManagerInterface): T $work
     *
     * @return T
     */
    public function write(callable $work): mixed
    {
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

    /** Whether the writer has connected yet -- false until the first record. */
    public function connected(): bool
    {
        return null !== $this->em && $this->em->getConnection()->isConnected();
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
        $events = $default->getEventManager();

        return $this->em = new EntityManager(
            $connection,
            $default->getConfiguration(),
            $events instanceof EventManager ? $events : null,
        );
    }
}
