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
/*
 * -- Why this package does not require symfony/config -----------------------
 *
 * It looks like it should. Bundle extends
 * DependencyInjection\Kernel\AbstractBundle, which implements
 * Config\Definition\ConfigurableInterface -- and symfony/dependency-injection
 * carries symfony/config in require-dev, not require. That exact chain is why
 * the theme packages had to declare it: installed alone they died with
 * Interface "...ConfigurableInterface" not found.
 *
 * This package does not, and the reason is measured rather than assumed:
 * installing coolms/core-doctrine alone from Packagist, with no path
 * repositories, loads this class successfully. symfony/config arrives because
 * doctrine/doctrine-bundle -- a hard require here -- requires it DIRECTLY
 * (not merely through symfony/framework-bundle).
 *
 * Recorded so the next sweep does not reopen it. What would change the
 * answer: doctrine-bundle dropping that requirement. The guarantee is real
 * but second-hand, so if this package ever stops requiring doctrine-bundle,
 * declare symfony/config here in the same commit.
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
