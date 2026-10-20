<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Transaction;

use function preg_match;
use function preg_replace;

/**
 * Whether a SQL statement writes or takes a lock -- the moment a unit of work's transaction must already be open.
 *
 * Writes: INSERT, UPDATE, DELETE, MERGE, TRUNCATE, COPY ... FROM, and DDL (CREATE, ALTER, DROP, COMMENT, GRANT,
 * REVOKE), also behind a WITH. Locks: SELECT ... FOR UPDATE / FOR NO KEY UPDATE / FOR SHARE / FOR KEY SHARE, LOCK TABLE,
 * and the transaction-scoped advisory locks (pg_advisory_xact_lock, pg_try_advisory_xact_lock and their _shared forms).
 * A session-scoped advisory lock (pg_advisory_lock) is not one: it outlives any transaction by design.
 *
 * Comments are ignored. Anything not recognised reads as a plain read: a missed write is then committed by the ORM's
 * own transaction as before, never lost.
 */
final class WriteOrLockStatement
{
    private const string WRITE = '~^\s*(?:WITH\b.*?\b)?(?:INSERT|UPDATE|DELETE|MERGE|TRUNCATE|CREATE|ALTER|DROP|COMMENT|GRANT|REVOKE)\b~is';

    private const string COPY_FROM = '~^\s*COPY\b.*\bFROM\b~is';

    private const string ROW_LOCK = '~\bFOR\s+(?:NO\s+KEY\s+UPDATE|UPDATE|KEY\s+SHARE|SHARE)\b~i';

    private const string TABLE_LOCK = '~^\s*LOCK\b~i';

    private const string XACT_ADVISORY = '~\bpg_(?:try_)?advisory_xact_lock(?:_shared)?\s*\(~i';

    public static function writesOrLocks(string $sql): bool
    {
        $sql = (string) preg_replace(['~/\*.*?\*/~s', '~--[^\n]*~'], ' ', $sql);

        return 1 === preg_match(self::WRITE, $sql)
            || 1 === preg_match(self::COPY_FROM, $sql)
            || 1 === preg_match(self::TABLE_LOCK, $sql)
            || 1 === preg_match(self::ROW_LOCK, $sql)
            || 1 === preg_match(self::XACT_ADVISORY, $sql);
    }
}
