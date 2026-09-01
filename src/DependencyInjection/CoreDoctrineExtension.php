<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\DependencyInjection;

use CoolMS\Core\Config\ConfigOverrideRepositoryInterface;
use CoolMS\Core\Doctrine\Config\ConfigOverrideRepository;
use CoolMS\Core\Doctrine\Inbox\DbalProcessedMessageStore;
use CoolMS\Core\Doctrine\Outbox\DbalOutboxRelayRepository;
use CoolMS\Core\Doctrine\Outbox\PersistingOutboxAppender;
use CoolMS\Core\Doctrine\Transaction\DoctrineTransactionRunner;
use CoolMS\Core\Doctrine\Type\DateRangeType;
use CoolMS\Core\Doctrine\Type\DateTimeRangeType;
use CoolMS\Core\Doctrine\Type\TimeRangeType;
use CoolMS\Core\Inbox\ProcessedMessageStoreInterface;
use CoolMS\Core\Outbox\OutboxAppenderInterface;
use CoolMS\Core\Outbox\OutboxRelayRepositoryInterface;
use CoolMS\Core\Transaction\TransactionRunnerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use CoolMS\Core\Doctrine\DependencyInjection\Compiler\ModuleMigrationPathsPass;
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

        // F7 -- the transactional-outbox append port. The concrete
        // PersistingOutboxAppender is registered + made public by the services
        // scan (#[Autoconfigure(public: true)]) so it survives before any
        // producer consumes the port; here we only alias the L0 contract to it.
        // The alias stays private and is pruned-as-unused until the first
        // producer migrates onto the outbox -- by design.
        $container->setAlias(OutboxAppenderInterface::class, PersistingOutboxAppender::class)
            ->setPublic(false);

        // F7 relay side (the read half of the outbox). The publisher stays in
        // core-bundle: dispatching is Messenger, not persistence.
        $container->setAlias(OutboxRelayRepositoryInterface::class, DbalOutboxRelayRepository::class)
            ->setPublic(false);

        // F7 §2 -- consumer idempotency store.
        $container->setAlias(ProcessedMessageStoreInterface::class, DbalProcessedMessageStore::class)
            ->setPublic(false);

        // DB-backed config overrides. The chain falls through to
        // FileConfigLoader whenever no override row exists.
        $container->setAlias(ConfigOverrideRepositoryInterface::class, ConfigOverrideRepository::class);
    }

    public function getAlias(): string
    {
        return 'coolms_core_doctrine';
    }

    /**
     * Doctrine configuration the adapter owns: the platform's custom column
     * types, and the XML mapping for Core's four persisted rows.
     *
     * Colocating both with the adapter keeps it self-sufficient -- no host
     * `doctrine.yaml` edit is required, and an application that drops this
     * package loses the Doctrine config along with the Doctrine classes rather
     * than being left with dangling references.
     */
    public function prepend(ContainerBuilder $container): void
    {
        // A package that ships its own tables gets its migrations/
        // directory registered. Here rather than in core-bundle: that one is
        // the framework integration and must not reach the ORM.
        new ModuleMigrationPathsPass()->prepend($container);

        $container->prependExtensionConfig('doctrine', [
            'dbal' => [
                'types' => [
                    DateRangeType::NAME => DateRangeType::class,
                    DateTimeRangeType::NAME => DateTimeRangeType::class,
                    TimeRangeType::NAME => TimeRangeType::class,
                ],
            ],
        ]);

        // Core's four persisted rows -- the transactional outbox,
        // the consumer-idempotency inbox (F7 §2), the sync change-feed
        // and the config-override store.
        //
        // XML, not attributes: the entity classes ship in `coolms/core`, which
        // must not import the ORM. The mapping therefore lives here, and travels
        // with this package.
        //
        // ONE mapping covers the whole `CoolMS\Core` prefix; the simplified XML
        // driver keys on the file name (`Outbox.OutboxRecord.orm.xml`).
        //
        // Warning: the driver owns that entire namespace. A new entity added
        // under CoolMS\Core WITHOUT a matching .orm.xml is simply not mapped,
        // and nothing reports it -- the class just never becomes an entity. Add
        // the file at the same time as the class.
        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'entity_managers' => [
                    'central' => [
                        'mappings' => [
                            'CoreDoctrine' => [
                                // NOT is_bundle: `dir` is resolved against the
                                // bundle directory otherwise. vendor/, not
                                // packages/, so it holds however this package
                                // is installed.
                                'is_bundle' => false,
                                'type' => 'xml',
                                'dir' => '%kernel.project_dir%/vendor/coolms/core-doctrine/src/mapping',
                                'prefix' => 'CoolMS\\Core',
                                'alias' => 'Core',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
