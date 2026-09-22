<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\DependencyInjection;

use CoolMS\Core\Doctrine\DependencyInjection\CoreDoctrineExtension;
use CoolMS\Core\Doctrine\Transaction\DoctrineTransactionRunner;
use CoolMS\Core\Doctrine\Type\DateRangeType;
use CoolMS\Core\Doctrine\Type\DateTimeRangeType;
use CoolMS\Core\Doctrine\Type\TimeRangeType;
use CoolMS\Core\Transaction\TransactionRunnerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * This extension IS the platform's commitment to Doctrine.
 *
 * Every alias here used to live in coolms/core-bundle, which meant the Symfony
 * integration depended on the ORM adapter and a second adapter could never win.
 * These tests pin the bindings so that coupling cannot creep back: if an alias
 * moves upstream again, the assertion here goes missing rather than the
 * regression going unnoticed.
 */
#[CoversClass(CoreDoctrineExtension::class)]
final class CoreDoctrineExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function bindings(): iterable
    {
        yield 'transaction runner' => [TransactionRunnerInterface::class, DoctrineTransactionRunner::class];
    }

    /**
     * @param class-string $contract
     * @param class-string $implementation
     */
    #[Test]
    #[DataProvider('bindings')]
    public function itBindsEachCoreContractToItsDoctrineImplementation(string $contract, string $implementation): void
    {
        $container = new ContainerBuilder();
        new CoreDoctrineExtension()->load([], $container);

        self::assertTrue(
            $container->hasAlias($contract),
            "$contract is not aliased -- nothing binds it to a persistence implementation.",
        );
        self::assertSame($implementation, (string) $container->getAlias($contract));
    }

    #[Test]
    public function itRegistersTheTransactionRunnerItAliases(): void
    {
        // The other four concretes are picked up by the application's service
        // scan; this one is registered here, so an alias without a definition
        // would fail only at container compile in the host app.
        $container = new ContainerBuilder();
        new CoreDoctrineExtension()->load([], $container);

        self::assertTrue($container->hasDefinition(DoctrineTransactionRunner::class));
    }

    #[Test]
    public function itPrependsThePlatformColumnTypes(): void
    {
        $container = new ContainerBuilder();
        new CoreDoctrineExtension()->prepend($container);

        $types = $this->doctrineConfig($container)['dbal']['types'];

        self::assertSame(DateRangeType::class, $types[DateRangeType::NAME]);
        self::assertSame(DateTimeRangeType::class, $types[DateTimeRangeType::NAME]);
        self::assertSame(TimeRangeType::class, $types[TimeRangeType::NAME]);
    }

    /**
     * ## The adapter maps NOTHING, and this is the test that says so.
     *
     * It used to prepend an XML mapping for four rows that shipped in
     * `coolms/core` -- the outbox, the idempotency journal, the sync
     * change-feed and the config-override store -- because a Domain package
     * must not import the ORM. Through September 2026 each of the four moved
     * to the module that reads and writes it, and the mapping directory went
     * with the last of them.
     *
     * Asserted rather than assumed: a platform package that maps an entity is
     * a platform package that installs a table, and the next one would arrive
     * exactly the way these did -- one file at a time, each reasonable on its
     * own.
     */
    #[Test]
    public function itPrependsNoEntityMappingAtAll(): void
    {
        $container = new ContainerBuilder();
        new CoreDoctrineExtension()->prepend($container);

        self::assertArrayNotHasKey('orm', $this->doctrineConfig($container));
        self::assertDirectoryDoesNotExist(dirname(__DIR__, 2) . '/src/mapping');
    }

    /**
     * @return array<string, mixed>
     */
    private function doctrineConfig(ContainerBuilder $container): array
    {
        $merged = [];
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            $merged = array_merge_recursive($merged, $config);
        }

        return $merged;
    }
}
