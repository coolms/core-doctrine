<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Transaction;

/** The statements one fake connection was asked to execute, in order. */
final class ExecutedStatements
{
    /** @var list<string> */
    public array $sql = [];
}
