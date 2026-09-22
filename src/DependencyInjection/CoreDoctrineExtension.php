<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\DependencyInjection;

use CoolMS\Core\Doctrine\Transaction\DoctrineTransactionRunner;
use CoolMS\Core\Doctrine\Type\DateRangeType;
use CoolMS\Core\Doctrine\Type\DateTimeRangeType;
use CoolMS\Core\Doctrine\Type\TimeRangeType;
use CoolMS\Core\Transaction\TransactionRunnerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

/**
 * Every place the platform commits to Doctrine, in one file.
 *
 * All of this used to sit in `coolms/core-bundle`, which made the Symfony
 * integration depend on the ORM adapter. Moving it here is what makes the
 * adapter swappable: the contracts stay in `coolms/core`, and whichever
 * adapter package is installed is the one that binds them.
 */
final class CoreDoctrineExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        // Cross-module transactional seam. Application-layer
        // orchestrators that span multiple Domain repositories under an
        // all-or-nothing wrap depend on TransactionRunnerInterface so they never
        // have to import a Doctrine EntityManager directly -- CoolmsArchitectureRule
        // fences Doctrine out of Application and Domain layers.
        $container->register(DoctrineTransactionRunner::class)
            ->setAutowired(true)
            ->setAutoconfigured(false)
            ->setPublic(false);
        $container->setAlias(TransactionRunnerInterface::class, DoctrineTransactionRunner::class);
    }

    public function getAlias(): string
    {
        return 'coolms_core_doctrine';
    }

    /**
     * Doctrine configuration the adapter owns: the platform's custom column
     * types.
     *
     * Colocating them with the adapter keeps it self-sufficient -- no host
     * `doctrine.yaml` edit is required, and an application that drops this
     * package loses the Doctrine config along with the Doctrine classes rather
     * than being left with dangling references.
     *
     * ## There is no entity mapping here any more, and that is the point
     *
     * This package used to carry an XML mapping for four persisted rows -- the
     * transactional outbox, the consumer-idempotency journal, the sync
     * change-feed and the config-override store -- because the entity classes
     * ship in `coolms/core`, which must not import the ORM. Through September
     * 2026 all four moved to the modules that read and write them (Messaging,
     * Sync, Settings), where they map by attribute like every other module's
     * rows. A platform package that installed tables for data only a module
     * writes is the thing those moves removed: the adapter now supplies
     * behaviour -- transactions, repositories, column types -- and owns no
     * table at all.
     */
    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('doctrine', [
            'dbal' => [
                'types' => [
                    DateRangeType::NAME => DateRangeType::class,
                    DateTimeRangeType::NAME => DateTimeRangeType::class,
                    TimeRangeType::NAME => TimeRangeType::class,
                ],
            ],
        ]);
    }
}
