<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A package that ships its own tables gets its `migrations/` directory
 * registered with Doctrine Migrations, so a module can carry its schema instead
 * of asking the application to carry it.
 *
 * Contributed the way this package's mappings are: prepended from
 * `CoreDoctrineExtension::prepend()`. It lives HERE and not in
 * `coolms/core-bundle` because that package is the framework integration and
 * must not reach the ORM -- choosing Doctrine is something this package does.
 *
 * The namespace is `<BundleNamespace>\Migrations` and the directory is
 * `<package root>/migrations`. Migration classes are not autoloaded -- Doctrine
 * requires the namespace only to tell one path's classes from another's -- so
 * the convention has to be followed by the files themselves.
 *
 * ⚠️ **This is for NEW migrations. An existing one cannot be moved here.**
 * `doctrine_migration_versions.version` stores the fully-qualified class name,
 * so moving `DoctrineMigrations\VersionX` to `Acme\CrmBundle\Migrations\VersionX`
 * makes Doctrine consider it unapplied and run it again on every database that
 * already has it -- which for a `CREATE TABLE` fails outright, and for a data
 * migration does something worse. The application's existing migrations stay
 * where they are; a package ships the ones it adds from now on.
 *
 * ⚠️ Ordering is by version identifier ACROSS all registered paths, not by
 * package: Doctrine sorts the whole set and package boundaries do not enter
 * into it. A package whose migration needs another package's table must
 * therefore carry a later identifier than it, and requiring that package is
 * what makes the relationship visible at all. Nothing enforces this yet.
 */
final readonly class ModuleMigrationPathsPass
{
    public function prepend(ContainerBuilder $container): void
    {
        $metadata = $container->getParameter('kernel.bundles_metadata');
        if (!is_array($metadata)) {
            throw new LogicException('kernel.bundles_metadata must be an array.');
        }

        $paths = [];

        foreach ($metadata as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $path = $entry['path'] ?? null;
            $namespace = $entry['namespace'] ?? null;
            if (!is_string($path) || !is_string($namespace)) {
                continue;
            }

            $path = rtrim($path, '/');

            // Bundle::getPath() returns the directory of the bundle class, so a
            // class in src/ yields <package>/src -- unless the bundle overrides
            // it to return the package root, which the ones carrying templates
            // do. Both conventions are live, so both are examined.
            //
            // ⚠️ The parent is examined ONLY when the path ends in `src`.
            // Climbing unconditionally leaves the package and lands in the
            // vendor NAMESPACE directory, where a sibling package can share the
            // name being looked for: every `vendor/doctrine/*` bundle matched
            // `vendor/doctrine/migrations`, which is the migrations LIBRARY,
            // and three of them were registered as migration sources.
            foreach (self::candidates($path, 'migrations') as $candidate) {
                if (is_dir($candidate)) {
                    $paths[$namespace . '\\Migrations'] = $candidate;
                    break;
                }
            }
        }

        ksort($paths);

        // Published whether or not anything was found, so "the pass ran and
        // found nothing" and "the pass never ran" are distinguishable from
        // outside. They look identical in the merged Doctrine config, which is
        // how a silent failure here would hide.
        $container->setParameter('coolms.module_migration_paths', $paths);

        if ([] === $paths) {
            return;
        }

        $container->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => $paths,
        ]);
    }

    /**
     * `<path>/<name>`, plus `<package>/<name>` when the path is a `src`
     * directory -- never a climb out of the package.
     *
     * @return list<string>
     */
    private static function candidates(string $path, string $name): array
    {
        $out = [$path . '/' . $name];

        if ('src' === basename($path)) {
            $out[] = dirname($path) . '/' . $name;
        }

        return $out;
    }
}
