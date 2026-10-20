<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Transaction;

use CoolMS\Core\Doctrine\Transaction\DoctrineFailurePathWriter;
use CoolMS\Core\Doctrine\Transaction\DoctrineUnitOfWork;
use CoolMS\Core\Doctrine\Transaction\LazyBeginConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function method_exists;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * A record written through the failure-path writer survives the discard of the unit of work around it. A file-backed
 * SQLite database, so the writer's own connection and the unit's see the same data -- in-memory SQLite is one database
 * per connection.
 */
#[CoversClass(DoctrineFailurePathWriter::class)]
final class DoctrineFailurePathWriterTest extends TestCase
{
    private string $file = '';
    private EntityManager $em;
    private DoctrineUnitOfWork $unit;
    private DoctrineFailurePathWriter $writer;

    #[Test]
    public function aRecordWrittenThroughItSurvivesTheDiscardOfTheUnitAroundIt(): void
    {
        // The record first, the unit's write after it: SQLite takes one writer at a time (Postgres, the platform's,
        // takes both in either order).
        $this->unit->begin();
        $this->writer->write(static function (EntityManagerInterface $em): void {
            $em->getConnection()->executeStatement("INSERT INTO evidence (v) VALUES ('the refusal')");
        });
        $this->em->getConnection()->executeStatement("INSERT INTO evidence (v) VALUES ('the refused change')");
        $this->unit->discard();

        self::assertSame(['the refusal'], $this->em->getConnection()->fetchFirstColumn('SELECT v FROM evidence ORDER BY v'));
    }

    #[Test]
    public function withNoUnitOpenARecordGoesThroughTheDefaultManagerAndItsSurroundingTransaction(): void
    {
        // No unit open: the default manager commits each write at once already. A transaction around its connection
        // (a test's rollback) takes the record with it, and the writer never opens a second connection.
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $this->writer->write(static function (EntityManagerInterface $em): void {
            $em->getConnection()->executeStatement("INSERT INTO evidence (v) VALUES ('outside any unit')");
        });
        $connection->rollBack();

        self::assertSame([], $connection->fetchFirstColumn('SELECT v FROM evidence'));
        self::assertFalse($this->writer->connected(), 'no connection of its own');
    }

    #[Test]
    public function withoutAnOwnerEveryRecordGoesThroughTheDefaultManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->em);
        $writer = new DoctrineFailurePathWriter($registry);

        $class = null;
        $writer->write(static function (EntityManagerInterface $em) use (&$class): void {
            $class = $em->getConnection()::class;
        });

        self::assertSame(LazyBeginConnection::class, $class, 'the default manager\'s connection');
        self::assertFalse($writer->connected());
    }

    #[Test]
    public function itConnectsOnlyAtTheFirstRecordInsideAUnitAndNeverOnTheLazyBeginConnection(): void
    {
        self::assertFalse($this->writer->connected(), 'nothing built before a record');

        $this->unit->begin();
        $class = null;
        $this->writer->write(static function (EntityManagerInterface $em) use (&$class): void {
            $class = $em->getConnection()::class;
        });

        self::assertTrue($this->writer->connected());
        self::assertNotSame(LazyBeginConnection::class, $class, 'no unit of work owns its connection');
        $this->unit->discard();
    }

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'fpw') . '.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->file, 'wrapperClass' => LazyBeginConnection::class]);
        $connection->executeStatement('CREATE TABLE evidence (v TEXT)');
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('CoolMSFailurePathTestProxies');
        // The highest ORM requires native lazy objects; the lowest CI resolves has no such setting.
        if (method_exists($config, 'enableNativeLazyObjects')) { // @phpstan-ignore function.alreadyNarrowedType
            $config->enableNativeLazyObjects(true);
        }
        $this->em = new EntityManager($connection, $config);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->em);
        $this->unit = new DoctrineUnitOfWork($registry);
        $this->writer = new DoctrineFailurePathWriter($registry, $this->unit);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
        @unlink($this->file);
    }
}
