<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Transaction;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * A middleware that changes nothing -- as an application's own are, to the writer: a write counter, a debug logger.
 * Its wrapper is what any middleware after it receives instead of the driver.
 */
final class PassThroughMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
        };
    }
}
