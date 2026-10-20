<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Transaction;

use Closure;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;

/**
 * The connection a unit of work's transaction begins on lazily: configure it as the connection's `wrapper_class`.
 *
 * While a unit is open and has not begun, the connection calls the unit's hook once, BEFORE the first statement that
 * writes or takes a lock ({@see WriteOrLockStatement}) and before any transaction the ORM opens for a flush -- so the
 * unit's transaction is always the outermost one and every later begin nests inside it as a savepoint. A unit that
 * only reads never reaches the hook: no BEGIN, no COMMIT.
 */
class LazyBeginConnection extends Connection
{
    private ?Closure $beforeFirstWrite = null;

    /** Armed by the unit of work when it opens a unit; fired at most once, then disarmed. */
    public function armBeforeFirstWrite(Closure $hook): void
    {
        $this->beforeFirstWrite = $hook;
    }

    public function disarm(): void
    {
        $this->beforeFirstWrite = null;
    }

    public function isArmed(): bool
    {
        return null !== $this->beforeFirstWrite;
    }

    public function beginTransaction(): void
    {
        $this->fire();
        parent::beginTransaction();
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        $this->fireFor($sql);

        return parent::executeStatement($sql, $params, $types);
    }

    public function executeQuery(
        string $sql,
        array $params = [],
        array $types = [],
        ?QueryCacheProfile $qcp = null,
    ): Result {
        $this->fireFor($sql);

        return parent::executeQuery($sql, $params, $types, $qcp);
    }

    public function prepare(string $sql): Statement
    {
        $this->fireFor($sql);

        return parent::prepare($sql);
    }

    private function fireFor(string $sql): void
    {
        if (null !== $this->beforeFirstWrite && WriteOrLockStatement::writesOrLocks($sql)) {
            $this->fire();
        }
    }

    private function fire(): void
    {
        $hook = $this->beforeFirstWrite;
        if (null === $hook) {
            return;
        }
        // Disarmed first: the hook begins a transaction through this very connection.
        $this->beforeFirstWrite = null;
        $hook();
    }
}
