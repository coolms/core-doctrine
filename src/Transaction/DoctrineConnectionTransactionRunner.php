<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Transaction;

use CoolMS\Core\Transaction\ConnectionTransactionRunnerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

/**
 * Doctrine-backed {@see ConnectionTransactionRunnerInterface}: begins / commits /
 * rolls back at the DBAL connection level (no EntityManager involved), so the commit
 * is immune to a closed EM — see the interface docblock for the F7 outbox-relay
 * rationale.
 *
 * Spelled out as begin/commit/rollback (rather than `Connection::transactional`) so
 * the generic return type flows cleanly and the contract is explicit.
 *
 * Lives under `Infrastructure\Doctrine\` per the architecture rule that fences
 * Doctrine imports out of every other layer (mirrors {@see DoctrineTransactionRunner}).
 */
#[AsAlias(ConnectionTransactionRunnerInterface::class)]
final readonly class DoctrineConnectionTransactionRunner implements ConnectionTransactionRunnerInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function run(callable $work): mixed
    {
        $this->connection->beginTransaction();

        try {
            $result = $work();
            $this->connection->commit();

            return $result;
        } catch (Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }
}
