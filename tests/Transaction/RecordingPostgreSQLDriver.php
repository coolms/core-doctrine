<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Transaction;

use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use LogicException;
use SensitiveParameter;

/** A PostgreSQL driver without a server: each connection it opens records the statements executed on it. */
final class RecordingPostgreSQLDriver extends AbstractPostgreSQLDriver
{
    /** @var list<ExecutedStatements> one per connection opened */
    public array $connections = [];

    public function connect(#[SensitiveParameter] array $params): Connection
    {
        $executed = new ExecutedStatements();
        $this->connections[] = $executed;

        return new class($executed) implements Connection {
            public function __construct(private readonly ExecutedStatements $executed)
            {
            }

            public function exec(string $sql): int
            {
                $this->executed->sql[] = $sql;

                return 0;
            }

            public function prepare(string $sql): Statement
            {
                throw new LogicException('Not this connection\'s use.');
            }

            public function query(string $sql): Result
            {
                throw new LogicException('Not this connection\'s use.');
            }

            public function quote(string $value): string
            {
                return "'" . $value . "'";
            }

            public function lastInsertId(): int
            {
                return 0;
            }

            public function beginTransaction(): void
            {
            }

            public function commit(): void
            {
            }

            public function rollBack(): void
            {
            }

            public function getServerVersion(): string
            {
                return '16.0';
            }

            public function getNativeConnection(): object
            {
                return $this;
            }
        };
    }
}
