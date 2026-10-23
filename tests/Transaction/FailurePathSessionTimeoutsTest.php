<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Transaction;

use CoolMS\Core\Doctrine\Transaction\DoctrineFailurePathWriter;
use CoolMS\Core\Doctrine\Transaction\DoctrineUnitOfWork;
use CoolMS\Core\Doctrine\Transaction\FailurePathSessionTimeouts;
use CoolMS\Core\Doctrine\Transaction\LazyBeginConnection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SQLiteDriver;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function method_exists;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The writer's session timeouts are the driver's: set on every connection it opens, a reconnect included, and only
 * on the writer's own connection.
 */
#[CoversClass(FailurePathSessionTimeouts::class)]
#[CoversClass(DoctrineFailurePathWriter::class)]
final class FailurePathSessionTimeoutsTest extends TestCase
{
    #[Test]
    public function everyConnectionAPostgreSQLDriverOpensSetsBothTimeoutsFirst(): void
    {
        $driver = new RecordingPostgreSQLDriver();
        $wrapped = new FailurePathSessionTimeouts(2000, 5000)->wrap($driver);

        $wrapped->connect([]);
        $wrapped->connect([]);

        $expected = ["SET lock_timeout = '2000ms'", "SET statement_timeout = '5000ms'"];
        self::assertSame(
            [$expected, $expected],
            array_map(static fn (ExecutedStatements $c): array => $c->sql, $driver->connections),
            'the reconnect too',
        );
    }

    #[Test]
    public function anyOtherDriverIsHandedBackAsItIs(): void
    {
        $sqlite = new SQLiteDriver();

        self::assertSame($sqlite, new FailurePathSessionTimeouts(2000, 5000)->wrap($sqlite));
    }

    #[Test]
    public function onlyTheWritersOwnConnectionCarriesThem(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'fps') . '.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file, 'wrapperClass' => LazyBeginConnection::class]);
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('CoolMSFailurePathSessionProxies');
        if (method_exists($config, 'enableNativeLazyObjects')) { // @phpstan-ignore function.alreadyNarrowedType
            $config->enableNativeLazyObjects(true);
        }
        $em = new EntityManager($connection, $config);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        $unit = new DoctrineUnitOfWork($registry);
        $writer = new DoctrineFailurePathWriter($registry, $unit);

        $unit->begin();
        $own = $writer->execute(static fn (Connection $c): array => array_map(
            static fn (object $middleware): string => $middleware::class,
            $c->getConfiguration()->getMiddlewares(),
        ));
        $unit->discard();
        $requests = array_map(static fn (object $middleware): string => $middleware::class, $connection->getConfiguration()->getMiddlewares());
        $connection->close();
        @unlink($file);

        self::assertContains(FailurePathSessionTimeouts::class, $own);
        self::assertNotContains(FailurePathSessionTimeouts::class, $requests, 'the request\'s connection is left as it was');
    }
}
