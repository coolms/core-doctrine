<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Transaction;

use CoolMS\Core\Doctrine\Transaction\WriteOrLockStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WriteOrLockStatement::class)]
final class WriteOrLockStatementTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function statements(): iterable
    {
        yield 'insert' => ['INSERT INTO t (v) VALUES (1)', true];
        yield 'update, lower case, leading blanks' => ["  \n update t set v = 2", true];
        yield 'delete' => ['DELETE FROM t WHERE v = 1', true];
        yield 'with ... insert' => ['WITH x AS (SELECT 1) INSERT INTO t SELECT * FROM x', true];
        yield 'with ... select' => ['WITH x AS (SELECT 1) SELECT * FROM x', false];
        yield 'truncate' => ['TRUNCATE t', true];
        yield 'create table' => ['CREATE TABLE u (v INT)', true];
        yield 'copy from' => ['COPY t FROM STDIN', true];
        yield 'copy to' => ['COPY t TO STDOUT', false];
        yield 'select' => ['SELECT v FROM t', false];
        yield 'select for update' => ['SELECT v FROM t WHERE v = 1 FOR UPDATE', true];
        yield 'select for no key update' => ['SELECT v FROM t FOR NO KEY UPDATE SKIP LOCKED', true];
        yield 'select for share' => ['SELECT v FROM t FOR SHARE', true];
        yield 'lock table' => ['LOCK TABLE t IN EXCLUSIVE MODE', true];
        yield 'transaction advisory lock' => ['SELECT pg_advisory_xact_lock(42)', true];
        yield 'try transaction advisory lock, shared' => ['SELECT pg_try_advisory_xact_lock_shared(42)', true];
        yield 'session advisory lock' => ['SELECT pg_advisory_lock(42)', false];
        yield 'a write word only in a comment' => ["SELECT v FROM t -- UPDATE later\n", false];
        yield 'a write word only in a block comment' => ['/* DELETE */ SELECT v FROM t', false];
        yield 'a column named update_at' => ['SELECT updated_at FROM t', false];
    }

    #[Test]
    #[DataProvider('statements')]
    public function aStatementIsAWriteOrALockOrNeither(string $sql, bool $expected): void
    {
        self::assertSame($expected, WriteOrLockStatement::writesOrLocks($sql));
    }
}
