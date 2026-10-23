<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Transaction;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;
use Exception;

/**
 * What the PostgreSQL driver raises when a statement is cancelled -- 55P03 by lock_timeout, 57014 by
 * statement_timeout -- for a database that cannot raise it: SQLite has neither timeout.
 */
final class CancelledByTheServer extends Exception implements DriverExceptionInterface
{
    public function __construct(private readonly string $sqlState)
    {
        parent::__construct('SQLSTATE[' . $sqlState . ']: canceling statement');
    }

    /** As DBAL hands it on: wrapped, with the statement and its parameters attached. */
    public static function on(string $sqlState, string $sql, string $parameter): DriverException
    {
        return new DriverException(new self($sqlState), new Query($sql, [$parameter], []));
    }

    public function getSQLState(): string
    {
        return $this->sqlState;
    }
}
