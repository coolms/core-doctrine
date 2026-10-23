<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Transaction;

use CoolMS\Core\Transaction\FailurePathWriterInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The failure-path writer as Doctrine code sees it: besides persist(), the two forms a record takes that a single
 * persist does not express. A consumer depends on this interface rather than on {@see DoctrineFailurePathWriter}, so
 * an application can put something in front of the writer -- a test environment that keeps the records inside its
 * own rolled-back transaction, for one.
 */
interface DoctrineFailurePathWriterInterface extends FailurePathWriterInterface
{
    /**
     * Runs $work with a manager whose writes are committed before the call returns, never part of the unit of work
     * around it -- an UPDATE of rows the request's own manager also reads, for one.
     *
     * @template T
     *
     * @param callable(EntityManagerInterface): T $work
     *
     * @return T
     */
    public function write(callable $work): mixed;

    /**
     * Runs $work with a plain connection, each statement committed as it runs -- a counter's upsert, for one: records
     * whose semantics are the statement's, not a transaction's.
     *
     * @template T
     *
     * @param callable(Connection): T $work
     *
     * @return T
     */
    public function execute(callable $work): mixed;
}
