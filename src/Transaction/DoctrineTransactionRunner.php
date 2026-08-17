<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Transaction;

use CoolMS\Core\Transaction\TransactionRunnerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Doctrine-backed {@see TransactionRunnerInterface} implementation.
 *
 * Delegates straight to `EntityManagerInterface::wrapInTransaction`
 * (which handles begin / commit / rollback + savepoint nesting on
 * supporting platforms) and `flush()` (for the deterministic
 * postFlush hook that `DomainEventPublisher` listens on).
 *
 * Lives under `Infrastructure\Doctrine\` per the architecture rule
 * that fences Doctrine ORM imports out of every other layer.
 */
final readonly class DoctrineTransactionRunner implements TransactionRunnerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function run(callable $work): mixed
    {
        return $this->em->wrapInTransaction($work);
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
