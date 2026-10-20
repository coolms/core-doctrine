<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Transaction;

use CoolMS\Core\Doctrine\Transaction\DoctrineUnitOfWork;
use CoolMS\Core\Doctrine\Transaction\LazyBeginConnection;
use CoolMS\Core\Transaction\UnitOfWorkException;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function sys_get_temp_dir;

#[CoversClass(DoctrineUnitOfWork::class)]
#[CoversClass(LazyBeginConnection::class)]
final class DoctrineUnitOfWorkTest extends TestCase
{
    private EntityManager $em;
    private LazyBeginConnection $connection;
    private DoctrineUnitOfWork $unit;

    #[Test]
    public function aUnitThatOnlyReadsBeginsNothing(): void
    {
        $this->unit->begin();
        $this->connection->fetchAllAssociative('SELECT v FROM t');

        self::assertSame(0, $this->connection->getTransactionNestingLevel(), 'a read begins no transaction');
        $this->unit->commit();
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    #[Test]
    public function theFirstWriteBeginsTheUnitAndCommitKeepsIt(): void
    {
        $this->unit->begin();
        $this->connection->executeStatement("INSERT INTO t (v) VALUES ('kept')");

        self::assertSame(1, $this->connection->getTransactionNestingLevel(), 'the write began the unit first');
        $this->unit->commit();
        self::assertSame(['kept'], $this->values());
    }

    #[Test]
    public function aDiscardLeavesNothing(): void
    {
        $this->unit->begin();
        $this->connection->executeStatement("INSERT INTO t (v) VALUES ('gone')");
        $this->unit->discard();

        self::assertSame([], $this->values());
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    #[Test]
    public function aBeginInsideTheUnitIsASavepointAndADiscardUndoesEveryLevel(): void
    {
        $this->unit->begin();
        $this->connection->beginTransaction(); // the ORM's flush transaction, say -- begun inside the unit's
        $this->connection->executeStatement("INSERT INTO t (v) VALUES ('nested')");

        self::assertSame(2, $this->connection->getTransactionNestingLevel(), 'the unit outermost, the inner a savepoint');
        $this->unit->discard();
        self::assertSame(0, $this->connection->getTransactionNestingLevel(), 'every level the failure left open');
        self::assertSame([], $this->values());
    }

    #[Test]
    public function aLockingReadBeginsTheUnit(): void
    {
        $this->unit->begin();
        // SQLite has no FOR UPDATE; the hook decides on the statement's text before it is sent.
        try {
            $this->connection->executeQuery('SELECT v FROM t FOR UPDATE');
        } catch (Throwable) {
        }

        self::assertSame(1, $this->connection->getTransactionNestingLevel(), 'a lock is taken inside the unit');
        $this->unit->discard();
    }

    #[Test]
    public function afterCommitEffectsRunAfterTheCommitAreDroppedOnADiscardAndRunAtOnceOutside(): void
    {
        $ran = [];
        $this->unit->begin();
        $this->unit->afterCommit(function () use (&$ran): void {
            $ran[] = 'committed';
        });
        self::assertSame([], $ran, 'not before the commit');
        $this->unit->commit();
        self::assertSame(['committed'], $ran);

        $this->unit->begin();
        $this->unit->afterCommit(function () use (&$ran): void {
            $ran[] = 'discarded';
        });
        $this->unit->discard();
        self::assertSame(['committed'], $ran, 'dropped on the discard');

        $this->unit->afterCommit(function () use (&$ran): void {
            $ran[] = 'outside';
        });
        self::assertSame(['committed', 'outside'], $ran, 'outside any unit: at once');
    }

    #[Test]
    public function aFailingEffectDoesNotUndoTheCommit(): void
    {
        $this->unit->begin();
        $this->connection->executeStatement("INSERT INTO t (v) VALUES ('stays')");
        $this->unit->afterCommit(static function (): void {
            throw new RuntimeException('the effect failed');
        });
        $this->unit->commit();

        self::assertSame(['stays'], $this->values());
    }

    #[Test]
    public function transactionalOutsideAUnitIsAShortUnitOfItsOwn(): void
    {
        $result = $this->unit->transactional(function (): string {
            $this->connection->executeStatement("INSERT INTO t (v) VALUES ('short')");

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['short'], $this->values());
        self::assertFalse($this->unit->ownerActive());
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    #[Test]
    public function transactionalInsideAUnitIsUndoneWithTheUnit(): void
    {
        $this->unit->begin();
        $this->unit->transactional(function (): void {
            $this->connection->executeStatement("INSERT INTO t (v) VALUES ('inner')");
        });
        self::assertSame(1, $this->connection->getTransactionNestingLevel(), 'the inner block released its savepoint, the unit stays open');
        $this->unit->discard();

        self::assertSame([], $this->values(), 'the owner decides, not the block');
    }

    #[Test]
    public function aUnitHasOneOwnerAndACommitNeedsAnOpenUnit(): void
    {
        $this->unit->begin();
        try {
            $this->unit->begin();
            self::fail('a second open was accepted');
        } catch (UnitOfWorkException) {
        }
        $this->unit->discard();

        $this->expectException(UnitOfWorkException::class);
        $this->unit->commit();
    }

    #[Test]
    public function aCommitWhileAFlushRunsIsRefused(): void
    {
        $this->unit->begin();
        $this->unit->preFlush();

        $this->expectException(UnitOfWorkException::class);
        try {
            $this->unit->commit();
        } finally {
            $this->unit->postFlush();
            $this->unit->discard();
        }
    }

    #[Test]
    public function aRollbackOnlyUnitIsNeverCommitted(): void
    {
        $this->unit->begin();
        $this->connection->executeStatement("INSERT INTO t (v) VALUES ('doomed')");
        $this->connection->setRollbackOnly();
        self::assertFalse($this->unit->isUsable());

        try {
            $this->unit->commit();
            self::fail('a rollback-only unit committed');
        } catch (UnitOfWorkException) {
        }
        self::assertSame([], $this->values(), 'discarded instead');
    }

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => LazyBeginConnection::class]);
        self::assertInstanceOf(LazyBeginConnection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('CREATE TABLE t (v TEXT)');
        // Built by hand: the ORMSetup helpers differ across the ORM 3.x versions CI resolves (lowest and highest).
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('CoolMSUnitOfWorkTestProxies');
        $this->em = new EntityManager($this->connection, $config);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->em);
        $this->unit = new DoctrineUnitOfWork($registry);
    }

    /** @return list<string> */
    private function values(): array
    {
        /* @var list<string> */
        return $this->connection->fetchFirstColumn('SELECT v FROM t ORDER BY v');
    }
}
