<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function count;
use function dirname;

/**
 * Every `use CoolMS\...` in src/ must resolve against the INSTALLED tree.
 *
 * This is the check `--prefer-lowest` cannot make on its own. PHP resolves a
 * `use` statement lazily, so a constraint floor that admits a version without
 * the class stays green for as long as no test happens to load the file that
 * imports it. That is not hypothetical: one package in this set declared a
 * floor its own source could not run against, no test referenced either of the
 * two files involved, and its lowest-resolution job passed for ten days.
 *
 * Reading the imports statically removes the dependence on coverage. Under the
 * `lowest` leg of the CI matrix the installed tree IS the floor, so a
 * constraint that has drifted below what the code needs fails here rather than
 * in somebody's application.
 */
final class ImportedClassesResolveTest extends TestCase
{
    public function testEveryImportedCoolmsClassResolves(): void
    {
        $src = dirname(__DIR__) . '/src';
        self::assertDirectoryExists($src);

        $checked = 0;
        $missing = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));

        foreach ($files as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
            self::assertIsArray($lines);

            foreach ($lines as $line) {
                // `use function` and `use const` import symbols, not types, so
                // the pattern requires the namespace to follow `use` directly.
                if (!preg_match('/^use\s+(CoolMS\\\\[A-Za-z0-9_\\\\]+)\s*(?:as\s+\w+\s*)?;/', $line, $m)) {
                    continue;
                }

                ++$checked;
                $class = $m[1];

                if (
                    class_exists($class) || interface_exists($class)
                    || trait_exists($class) || enum_exists($class)
                ) {
                    continue;
                }

                $missing[$class] = sprintf(
                    '%s (imported by %s)',
                    $class,
                    str_replace($src . '/', '', $file->getPathname()),
                );
            }
        }

        // A scan that matched nothing is a broken test, not a passing one --
        // a change to the pattern or the layout must fail loudly here.
        self::assertGreaterThan(
            0,
            $checked,
            'no CoolMS imports were found under src/ -- the scan is broken, not clean',
        );

        self::assertSame([], array_values($missing), sprintf(
            '%d of %d imported CoolMS classes are absent from the installed tree. '
            . 'A constraint floor is lower than what this code needs.',
            count($missing),
            $checked,
        ));
    }
}
