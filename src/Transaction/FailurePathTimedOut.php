<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Transaction;

use RuntimeException;
use Throwable;

/**
 * A failure-path write that waited past its lock timeout or ran past its statement timeout, and was cancelled.
 *
 * The writer has logged it by then. A caller that only records -- an audit row, a counter -- carries on without it:
 * the request is never held up by its own bookkeeping. A caller whose write IS the outcome -- a revocation, a
 * single-use nonce -- lets it propagate, and the request fails rather than report a change that was not made.
 */
final class FailurePathTimedOut extends RuntimeException
{
    public function __construct(public readonly string $sqlState, Throwable $previous)
    {
        parent::__construct('A failure-path write was cancelled by its ' . self::which($sqlState) . '.', 0, $previous);
    }

    private static function which(string $sqlState): string
    {
        return '55P03' === $sqlState ? 'lock timeout' : 'statement timeout';
    }
}
