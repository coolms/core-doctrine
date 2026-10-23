<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Transaction;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

use function sprintf;

/**
 * The failure-path writer's session timeouts, set by the driver on every connection it opens -- a reconnect
 * included -- so no statement of the writer's reaches the server without them. PostgreSQL only: other drivers are
 * handed back as they are.
 *
 * Only on the writer's own connection: it is built on a copy of the default connection's configuration, never on the
 * configuration itself, which the request's connection shares.
 */
final readonly class FailurePathSessionTimeouts implements Middleware
{
    public function __construct(
        private int $lockTimeoutMs,
        private int $statementTimeoutMs,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        if (!$driver instanceof AbstractPostgreSQLDriver) {
            return $driver;
        }
        $statements = [
            sprintf("SET lock_timeout = '%dms'", $this->lockTimeoutMs),
            sprintf("SET statement_timeout = '%dms'", $this->statementTimeoutMs),
        ];

        return new class($driver, $statements) extends AbstractDriverMiddleware {
            /** @param list<string> $statements */
            public function __construct(Driver $driver, private readonly array $statements)
            {
                parent::__construct($driver);
            }

            public function connect(#[SensitiveParameter] array $params): DriverConnection
            {
                $connection = parent::connect($params);
                foreach ($this->statements as $statement) {
                    $connection->exec($statement);
                }

                return $connection;
            }
        };
    }
}
