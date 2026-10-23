<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Transaction;

use Closure;
use CoolMS\Core\Doctrine\Transaction\DoctrineFailurePathWriter;
use CoolMS\Core\Doctrine\Transaction\DoctrineUnitOfWork;
use CoolMS\Core\Doctrine\Transaction\FailurePathTimedOut;
use CoolMS\Core\Doctrine\Transaction\LazyBeginConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

use function json_encode;
use function method_exists;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * A failure-path write cancelled by its lock or statement timeout: logged by SQLSTATE -- never by its SQL, whose
 * parameters may be a credential -- and thrown, except by persist(), which only ever carries a record and lets the
 * request go on. The timeouts themselves are PostgreSQL's; here the server's cancellation is raised by hand.
 */
#[CoversClass(DoctrineFailurePathWriter::class)]
#[CoversClass(FailurePathTimedOut::class)]
final class FailurePathTimeoutTest extends TestCase
{
    private string $file = '';
    private EntityManager $em;
    private DoctrineUnitOfWork $unit;
    private DoctrineFailurePathWriter $writer;
    private RecordingLogger $logger;

    #[Test]
    public function aStatementCancelledByItsLockTimeoutIsLoggedByItsStateAndThrown(): void
    {
        $this->unit->begin();

        try {
            $this->writer->execute(self::raising(CancelledByTheServer::on('55P03', 'UPDATE token SET revoked = true WHERE hash = ?', 'secret-token-hash')));
            self::fail('a cancelled statement is never a result');
        } catch (FailurePathTimedOut $timedOut) {
            self::assertSame('55P03', $timedOut->sqlState);
            self::assertInstanceOf(DriverException::class, $timedOut->getPrevious());
        } finally {
            $this->unit->discard();
        }

        self::assertCount(1, $this->logger->records);
        [$level, $message, $context] = $this->logger->records[0];
        self::assertSame('error', $level);
        self::assertSame(['what' => 'a statement', 'timeout' => 'lock timeout of 2000 ms', 'sqlstate' => '55P03'], $context);
        self::assertStringNotContainsString('secret-token-hash', $message . json_encode($context), 'never the SQL\'s parameters');
        self::assertStringNotContainsString('UPDATE token', $message . json_encode($context), 'never the SQL');
    }

    #[Test]
    public function aWriteCancelledByItsStatementTimeoutIsThrownToo(): void
    {
        $this->unit->begin();

        try {
            $this->writer->write(self::raising(CancelledByTheServer::on('57014', 'DELETE FROM nonce WHERE id = ?', 'n')));
            self::fail('a cancelled write is never a result');
        } catch (FailurePathTimedOut $timedOut) {
            self::assertSame('57014', $timedOut->sqlState);
        } finally {
            $this->unit->discard();
        }

        self::assertSame('statement timeout of 5000 ms', $this->logger->records[0][2]['timeout']);
    }

    #[Test]
    public function aRecordCancelledByATimeoutIsDroppedAndTheRequestGoesOn(): void
    {
        $this->em->getEventManager()->addEventListener(Events::onFlush, new class($this->em) {
            public function __construct(private readonly EntityManager $requests)
            {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                if ($args->getObjectManager() !== $this->requests) {
                    throw CancelledByTheServer::on('55P03', 'INSERT INTO evidence (v) VALUES (?)', 'the refusal');
                }
            }
        });
        $this->unit->begin();

        $this->writer->persist(new EvidenceRecord('the refusal'));
        $this->unit->discard();

        self::assertSame([], $this->em->getConnection()->fetchFirstColumn('SELECT v FROM evidence'), 'dropped, not written');
        self::assertSame('a record of ' . EvidenceRecord::class, $this->logger->records[0][2]['what']);
    }

    #[Test]
    public function aFailureThatIsNoTimeoutIsThrownAsItCameAndNotLogged(): void
    {
        $this->unit->begin();

        try {
            $this->writer->execute(self::raising(CancelledByTheServer::on('23505', 'INSERT INTO evidence (v) VALUES (?)', 'twice')));
            self::fail('a unique violation is never a result');
        } catch (Throwable $failed) {
            self::assertNotInstanceOf(FailurePathTimedOut::class, $failed, 'a unique violation is not a timeout');
            self::assertInstanceOf(DriverException::class, $failed);
            self::assertSame('23505', $failed->getSQLState());
        } finally {
            $this->unit->discard();
        }

        self::assertSame([], $this->logger->records);
    }

    #[Test]
    public function withNoUnitOpenTheRequestsOwnConnectionIsLeftAsItIs(): void
    {
        // The timeouts are the writer's own connection's: with no unit open the write runs on the request's, and
        // what that connection raises is the caller's to see, as it came.
        $this->expectException(DriverException::class);
        $this->writer->execute(self::raising(CancelledByTheServer::on('55P03', 'SELECT 1', 'x')));
    }

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'fpt') . '.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->file, 'wrapperClass' => LazyBeginConnection::class]);
        $connection->executeStatement('CREATE TABLE evidence (v TEXT PRIMARY KEY)');
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__]));
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('CoolMSFailurePathTimeoutProxies');
        // The highest ORM requires native lazy objects; the lowest CI resolves has no such setting.
        if (method_exists($config, 'enableNativeLazyObjects')) { // @phpstan-ignore function.alreadyNarrowedType
            $config->enableNativeLazyObjects(true);
        }
        $this->em = new EntityManager($connection, $config);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->em);
        $this->unit = new DoctrineUnitOfWork($registry);
        $this->logger = new RecordingLogger();
        $this->writer = new DoctrineFailurePathWriter($registry, $this->unit, $this->logger);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
        @unlink($this->file);
    }

    /**
     * Work that raises $raised: typed as returning, as work does, so what follows the call is not taken for dead
     * code by a reader -- or an analyser -- that infers "never" from a body that only throws.
     *
     * @return Closure(): int
     */
    private static function raising(?Throwable $raised): Closure
    {
        return static function () use ($raised): int {
            if (null !== $raised) {
                throw $raised;
            }

            return 0;
        };
    }
}
