<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Health;

use CoolMS\Core\Health\DependencyState;
use CoolMS\Core\Health\LivenessProbeInterface;
use Doctrine\DBAL\Connection;
use Throwable;

use function sprintf;

/**
 * Is the database answering?
 *
 * A real round trip, not a check that a DSN is set: `SELECT 1` fails when the server
 * is down, when credentials are wrong, and when the connection pool is exhausted --
 * three states in which an installation is broken while its configuration looks
 * perfect.
 *
 * Almost everything else fails first if this does, so it is here for completeness
 * rather than suspense. But a report that covered seven dependencies and stayed quiet
 * about the eighth would invite exactly the wrong conclusion on the day all seven
 * probes fail at once.
 *
 * It lives in core-doctrine because it names `Doctrine\DBAL\Connection`, and the
 * adapter package is where Doctrine is allowed to be named.
 */
final readonly class DatabaseLivenessProbe implements LivenessProbeInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function check(): DependencyState
    {
        $ask = 'SELECT 1';

        try {
            $this->connection->executeQuery($ask)->fetchOne();
        } catch (Throwable $e) {
            return DependencyState::silent('Database', $ask, sprintf('%s: %s', $e::class, $e->getMessage()));
        }

        return DependencyState::answered(
            'Database',
            $ask,
            sprintf('answered over %s', $this->connection->getDatabasePlatform()::class),
        );
    }
}
