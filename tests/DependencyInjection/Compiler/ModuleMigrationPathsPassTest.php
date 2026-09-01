<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\DependencyInjection\Compiler;

use CoolMS\Core\Doctrine\DependencyInjection\Compiler\ModuleMigrationPathsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * No package ships migrations yet, so the pass is exercised against fixture
 * directories: a mechanism that has never been run is not a mechanism, and
 * "it will work when someone uses it" is the claim under test.
 */
final class ModuleMigrationPathsPassTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    public function testAPackageShippingMigrationsIsRegistered(): void
    {
        $root = $this->makePackage('with-migrations', true);

        $container = $this->container([
            'AcmeCrmBundle' => ['path' => $root . '/src', 'namespace' => 'Acme\\CrmBundle'],
        ]);
        new ModuleMigrationPathsPass()->prepend($container);

        $config = $container->getExtensionConfig('doctrine_migrations');
        self::assertNotSame([], $config, 'the path must be contributed');
        self::assertSame(
            ['Acme\\CrmBundle\\Migrations' => $root . '/migrations'],
            $config[0]['migrations_paths'],
        );
    }

    public function testAPackageWithoutMigrationsContributesNothing(): void
    {
        $root = $this->makePackage('no-migrations', false);

        $container = $this->container([
            'AcmeCrmBundle' => ['path' => $root . '/src', 'namespace' => 'Acme\\CrmBundle'],
        ]);
        new ModuleMigrationPathsPass()->prepend($container);

        self::assertSame(
            [],
            $container->getExtensionConfig('doctrine_migrations'),
            'contributing an empty path list would register a namespace with no '
            . 'files behind it, which is a promise of migrations that do not exist',
        );
    }

    public function testTheBundleClassMaySitInSrcOrAtThePackageRoot(): void
    {
        $root = $this->makePackage('root-bundle', true);

        // getPath() returning the package root -- the convention a bundle with
        // templates uses -- must find the same directory as one returning src/.
        $container = $this->container([
            'AcmeCrmBundle' => ['path' => $root, 'namespace' => 'Acme\\CrmBundle'],
        ]);
        new ModuleMigrationPathsPass()->prepend($container);

        $config = $container->getExtensionConfig('doctrine_migrations');
        self::assertSame(
            ['Acme\\CrmBundle\\Migrations' => $root . '/migrations'],
            $config[0]['migrations_paths'],
        );
    }

    public function testItNeverClimbsOutOfThePackage(): void
    {
        // A bundle whose getPath() is already the package root, with a SIBLING
        // directory called `migrations` beside it -- the shape of every
        // vendor/doctrine/* bundle, where `vendor/doctrine/migrations` is the
        // migrations LIBRARY. Climbing unconditionally registered three of them
        // as migration sources; the real container caught it and this pins it.
        $vendorNamespace = sys_get_temp_dir() . '/coolms-vendor-' . bin2hex(random_bytes(5));
        mkdir($vendorNamespace . '/some-bundle', 0o777, true);
        mkdir($vendorNamespace . '/migrations', 0o777, true);
        $this->temp[] = $vendorNamespace . '/some-bundle';
        $this->temp[] = $vendorNamespace . '/migrations';
        $this->temp[] = $vendorNamespace;

        $container = $this->container([
            'SomeBundle' => [
                'path' => $vendorNamespace . '/some-bundle',
                'namespace' => 'Some\\Bundle',
            ],
        ]);
        new ModuleMigrationPathsPass()->prepend($container);

        self::assertSame(
            [],
            $container->getParameter('coolms.module_migration_paths'),
            'a sibling package that happens to be named `migrations` is not this '
            . 'package\'s migrations directory',
        );
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temp) as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        $this->temp = [];
    }

    /** @param array<string, array{path: string, namespace: string}> $bundles */
    private function container(array $bundles): ContainerBuilder
    {
        $c = new ContainerBuilder();
        $c->setParameter('kernel.bundles_metadata', $bundles);

        return $c;
    }

    private function makePackage(string $label, bool $withMigrations): string
    {
        $root = sys_get_temp_dir() . '/coolms-mig-' . $label . '-' . bin2hex(random_bytes(5));
        foreach ([$root, $root . '/src'] as $dir) {
            mkdir($dir, 0o777, true);
            $this->temp[] = $dir;
        }
        if ($withMigrations) {
            mkdir($root . '/migrations', 0o777, true);
            $this->temp[] = $root . '/migrations';
        }

        return $root;
    }
}
