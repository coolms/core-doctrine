<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine;

use CoolMS\Core\Doctrine\DependencyInjection\Compiler\ModuleMigrationPathsPass;
use CoolMS\Core\Doctrine\DependencyInjection\CoreDoctrineExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Binds coolms/core's persistence contracts to their Doctrine implementations.
 *
 * This bundle exists so that CHOOSING Doctrine is something this package does,
 * not something the platform bundle does. `coolms/core-bundle` used to alias
 * `TransactionRunnerInterface`, the outbox/inbox ports and the config-override
 * repository straight to the classes in here -- which meant a second adapter
 * could be installed and still never win, because the aliases were decided
 * upstream and Doctrine came along as a hard dependency regardless.
 *
 * The seam is declared in composer.json: this package `provide`s
 * `coolms/core-persistence-implementation`, which `coolms/core-module` requires.
 * An alternative adapter satisfies the same virtual package and replaces this
 * bundle wholesale.
 */
final class CoreDoctrineBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // A package that ships its own tables gets its `migrations/` directory
        // registered. In THIS package because choosing the ORM is what it does;
        // `coolms/core-bundle` is the framework integration and must not reach it.
        //
        // In build() rather than the extension's prepend(): the pass reads
        // `kernel.bundles_metadata`, which build() is guaranteed and an extension
        // called with a bare container is not.
        new ModuleMigrationPathsPass()->prepend($container);
    }

    // Narrower than the parent's `?ExtensionInterface`: this bundle always has
    // an extension, and saying so spares every caller a null check.
    public function getContainerExtension(): ExtensionInterface
    {
        return new CoreDoctrineExtension();
    }
}
