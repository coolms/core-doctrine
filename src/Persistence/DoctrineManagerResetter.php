<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Persistence;

use CoolMS\Core\Persistence\ManagerResetterInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Doctrine-backed {@see ManagerResetterInterface}: delegates to
 * {@see ManagerRegistry::resetManager}, which replaces the (possibly closed)
 * default EntityManager with a fresh OPEN one wrapping the SAME connection — so an
 * ambient transaction on that connection (the relay's batch tx) is preserved.
 *
 * Lives under `Infrastructure\Doctrine\` per the architecture rule that fences
 * Doctrine imports out of every other layer (mirrors `DoctrineTransactionRunner`).
 */
#[AsAlias(ManagerResetterInterface::class)]
final readonly class DoctrineManagerResetter implements ManagerResetterInterface
{
    public function __construct(
        private ManagerRegistry $registry,
    ) {
    }

    public function reset(): void
    {
        $this->registry->resetManager();
    }
}
