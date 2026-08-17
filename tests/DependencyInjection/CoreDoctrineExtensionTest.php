<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\DependencyInjection;

use CoolMS\Core\Config\ConfigOverrideRepositoryInterface;
use CoolMS\Core\Doctrine\Config\ConfigOverrideRepository;
use CoolMS\Core\Doctrine\DependencyInjection\CoreDoctrineExtension;
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
        yield 'outbox appender' => [OutboxAppenderInterface::class, PersistingOutboxAppender::class];
        yield 'outbox relay repository' => [OutboxRelayRepositoryInterface::class, DbalOutboxRelayRepository::class];
        yield 'processed message store' => [ProcessedMessageStoreInterface::class, DbalProcessedMessageStore::class];
        yield 'config override repository' => [ConfigOverrideRepositoryInterface::class, ConfigOverrideRepository::class];
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
     * The mapping is XML because the entity classes ship in coolms/core, which
     * must not import the ORM. `is_bundle: false` matters: with it true,
     * Doctrine resolves `dir` against a bundle directory and the path silently
     * fails to find the mapping files.
     */
    #[Test]
    public function itPrependsTheEntityMappingAsStandaloneXml(): void
    {
        $container = new ContainerBuilder();
        new CoreDoctrineExtension()->prepend($container);

        $mapping = $this->doctrineConfig($container)['orm']['entity_managers']['central']['mappings']['CoreDoctrine'];

        self::assertFalse($mapping['is_bundle']);
        self::assertSame('xml', $mapping['type']);
        self::assertSame('CoolMS\Core', $mapping['prefix']);
        self::assertStringEndsWith('/vendor/coolms/core-doctrine/src/mapping', $mapping['dir']);
    }

    /**
     * Every entity the mapping prefix claims must have a file, because the
     * driver reports nothing when one is missing -- the class simply never
     * becomes an entity.
     */
    #[Test]
    public function everyMappingFileIsNamedForTheClassItMaps(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/src/mapping/*.orm.xml') ?: [];

        self::assertNotEmpty($files, 'the mapping directory is empty');

        foreach ($files as $file) {
            $xml = (string) file_get_contents($file);
            self::assertMatchesRegularExpression(
                '/<entity name="CoolMS\\\\Core\\\\[A-Za-z\\\\]+"/',
                $xml,
                basename($file) . ' does not map a CoolMS\Core class',
            );

            // SimplifiedXmlDriver derives the file name from the class name
            // minus the prefix, dots for separators.
            preg_match('/<entity name="CoolMS\\\\Core\\\\([A-Za-z\\\\]+)"/', $xml, $m);
            $expected = str_replace('\\', '.', $m[1]) . '.orm.xml';
            self::assertSame($expected, basename($file));
        }
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
