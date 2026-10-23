<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Transaction;

use Psr\Log\AbstractLogger;
use Stringable;

/** Every record as it was logged: level, message, context. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }
}
