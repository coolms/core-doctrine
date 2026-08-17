<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Backup;

use CoolMS\Core\Backup\BackupException;
use CoolMS\Core\Backup\TableBackupPortInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Throwable;

use function array_chunk;
use function array_fill;
use function count;
use function implode;
use function in_array;
use function is_bool;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * DBAL adapter for {@see TableBackupPortInterface} — the one tested engine every
 * backup contributor reuses instead of hand-mapping entity fields (which would
 * couple to accessor APIs and drift on schema change).
 *
 * Restore does per-row `DELETE FROM t WHERE id` + `INSERT` with NO wrapping
 * transaction: the delete+insert is idempotent, so a partial restore self-heals
 * by re-running (mirrors the retention-pruner soft-ref lesson; avoids
 * nested-transaction fragility under the test rollback harness).
 */
final readonly class DoctrineTableBackup implements TableBackupPortInterface
{
    /** Guard against identifier injection: table + column names come from contributor code, never user input, but validate anyway. */
    private const string IDENTIFIER_PATTERN = '/^[a-z_][a-z0-9_]*$/';

    /** Cap DELETE `IN (...)` list size so a large reconcile stays under the driver's placeholder limit. */
    private const int DELETE_CHUNK = 500;

    /** Composite reconcile binds K params per key (tuple IN), so chunk tighter to keep the placeholder count bounded. */
    private const int COMPOSITE_DELETE_CHUNK = 200;

    public function __construct(private Connection $connection)
    {
    }

    public function dumpTable(string $table): array
    {
        $rows = [];
        foreach ($this->streamTable($table) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function streamTable(string $table): iterable
    {
        // Validate EAGERLY, not inside the generator: a generator body does not run
        // until first iteration, which would defer the identifier guard past the
        // point a caller could reasonably expect it to have rejected the name.
        $this->assertTableName($table);

        // Strip DB-generated columns (e.g. the generated `v_*` extras mirrors,
        // `GENERATED ALWAYS AS ... STORED`): they are derived, can't be INSERTed,
        // and are recomputed from their source on restore. Excluding them keeps the
        // bundle source-only and lets the row round-trip through a plain INSERT.
        // Resolved ONCE, before the cursor opens — not per row.
        return $this->streamRows($table, $this->generatedColumns($table));
    }

    public function dumpRowsByIds(string $table, string $idColumn, array $ids): array
    {
        $this->assertTableName($table);
        $this->assertColumnName($idColumn);
        if ([] === $ids) {
            return [];
        }

        // Same DB-generated-column strip as dumpTable so hydrated rows re-INSERT cleanly.
        $generated = $this->generatedColumns($table);

        $rows = [];
        foreach (array_chunk($ids, self::DELETE_CHUNK) as $chunk) {
            $chunkRows = $this->connection->fetchAllAssociative(
                sprintf('SELECT * FROM %s WHERE %s IN (?)', $table, $idColumn),
                [$chunk],
                [ArrayParameterType::STRING],
            );
            foreach ($chunkRows as $row) {
                foreach ($generated as $column) {
                    unset($row[$column]);
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function restoreRows(string $table, array $rows): int
    {
        $this->assertTableName($table);

        $generated = $this->generatedColumns($table);

        $restored = 0;
        foreach ($rows as $row) {
            // JSON object keys are always strings; narrow explicitly for DBAL.
            // Derive a per-column bind type from each value's PHP type: JSON only
            // carries null/bool/int/float/string, and DBAL's default string bind
            // turns a bool `false` into '' — which Postgres rejects for a boolean
            // column. Explicit BOOLEAN/INTEGER/NULL types keep the round-trip
            // lossless; JSONB/timestamp/uuid columns arrive as strings and cast fine.
            $columns = [];
            $types = [];
            foreach ($row as $column => $value) {
                if (!is_string($column) || in_array($column, $generated, true)) {
                    continue; // skip non-string keys + DB-generated columns (can't INSERT)
                }
                $columns[$column] = $value;
                $types[$column] = match (true) {
                    is_bool($value) => ParameterType::BOOLEAN,
                    is_int($value) => ParameterType::INTEGER,
                    null === $value => ParameterType::NULL,
                    default => ParameterType::STRING,
                };
            }

            if (isset($columns['id'])) {
                $this->connection->delete($table, ['id' => $columns['id']]);
            }
            $this->connection->insert($table, $columns, $types);
            ++$restored;
        }

        return $restored;
    }

    public function updateRow(string $table, int|string $id, array $columns): void
    {
        $this->assertTableName($table);

        $generated = $this->generatedColumns($table);

        // Same per-column bind-type derivation as restoreRows (a bool `false`
        // bound as the default '' is rejected by a Postgres boolean column).
        $set = [];
        $types = [];
        foreach ($columns as $column => $value) {
            if (in_array($column, $generated, true)) {
                continue; // never write a DB-generated column
            }
            $set[$column] = $value;
            $types[$column] = match (true) {
                is_bool($value) => ParameterType::BOOLEAN,
                is_int($value) => ParameterType::INTEGER,
                null === $value => ParameterType::NULL,
                default => ParameterType::STRING,
            };
        }

        if ([] === $set) {
            return;
        }

        $this->connection->update($table, $set, ['id' => $id], $types);
    }

    public function liveIds(string $table, string $idColumn, array $scopeEquals = []): array
    {
        $this->assertTableName($table);
        $this->assertColumnName($idColumn);

        $sql = sprintf('SELECT %s FROM %s', $idColumn, $table);
        $params = [];
        $types = [];
        $where = [];
        foreach ($scopeEquals as $column => $value) {
            $this->assertColumnName($column);
            $where[] = sprintf('%s = ?', $column);
            $params[] = $value;
            $types[] = match (true) {
                is_bool($value) => ParameterType::BOOLEAN,
                is_int($value) => ParameterType::INTEGER,
                default => ParameterType::STRING,
            };
        }
        if ([] !== $where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $ids = [];
        foreach ($this->connection->fetchFirstColumn($sql, $params, $types) as $value) {
            if (null !== $value) {
                $ids[] = (string) $value;
            }
        }

        return $ids;
    }

    public function deleteByIds(string $table, string $idColumn, array $ids): int
    {
        $this->assertTableName($table);
        $this->assertColumnName($idColumn);
        if ([] === $ids) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($ids, self::DELETE_CHUNK) as $chunk) {
            $deleted += (int) $this->connection->executeStatement(
                sprintf('DELETE FROM %s WHERE %s IN (?)', $table, $idColumn),
                [$chunk],
                [ArrayParameterType::STRING],
            );
        }

        return $deleted;
    }

    public function liveIdsWhereIn(string $table, string $idColumn, string $membershipColumn, array $allowedValues): array
    {
        $this->assertTableName($table);
        $this->assertColumnName($idColumn);
        $this->assertColumnName($membershipColumn);
        if ([] === $allowedValues) {
            return [];
        }

        $ids = [];
        foreach (array_chunk($allowedValues, self::DELETE_CHUNK) as $chunk) {
            $rows = $this->connection->fetchFirstColumn(
                sprintf('SELECT %s FROM %s WHERE %s IN (?)', $idColumn, $table, $membershipColumn),
                [$chunk],
                [ArrayParameterType::STRING],
            );
            foreach ($rows as $value) {
                if (null !== $value) {
                    $ids[] = (string) $value;
                }
            }
        }

        return $ids;
    }

    public function liveCompositeKeys(string $table, array $keyColumns, array $scopeEquals = []): array
    {
        $this->assertTableName($table);
        $this->assertKeyColumns($table, $keyColumns);

        $sql = sprintf('SELECT %s FROM %s', implode(', ', $keyColumns), $table);
        $params = [];
        $types = [];
        $where = [];
        foreach ($scopeEquals as $column => $value) {
            $this->assertColumnName($column);
            $where[] = sprintf('%s = ?', $column);
            $params[] = $value;
            $types[] = match (true) {
                is_bool($value) => ParameterType::BOOLEAN,
                is_int($value) => ParameterType::INTEGER,
                default => ParameterType::STRING,
            };
        }
        if ([] !== $where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $keys = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params, $types) as $row) {
            $key = [];
            foreach ($keyColumns as $column) {
                $value = $row[$column] ?? null;
                if (null === $value) {
                    continue 2; // a composite PK column is NOT NULL; skip defensively
                }
                $key[$column] = (string) $value;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    public function deleteByCompositeKeys(string $table, array $keyColumns, array $keys): int
    {
        $this->assertTableName($table);
        $this->assertKeyColumns($table, $keyColumns);
        if ([] === $keys) {
            return 0;
        }

        $columnList = implode(', ', $keyColumns);
        // Portable row-value tuple membership: `(c1, c2) IN ((?,?), (?,?), …)`.
        // Postgres / MySQL / SQLite all accept it; positional string binds suit the
        // UUID keys. Chunked so a large reconcile stays under the placeholder limit.
        $tuplePlaceholder = '(' . implode(', ', array_fill(0, count($keyColumns), '?')) . ')';

        $deleted = 0;
        foreach (array_chunk($keys, self::COMPOSITE_DELETE_CHUNK) as $chunk) {
            $tuples = [];
            $params = [];
            foreach ($chunk as $key) {
                $tuples[] = $tuplePlaceholder;
                foreach ($keyColumns as $column) {
                    $params[] = $key[$column] ?? '';
                }
            }
            $deleted += (int) $this->connection->executeStatement(
                sprintf('DELETE FROM %s WHERE (%s) IN (%s)', $table, $columnList, implode(', ', $tuples)),
                $params,
            );
        }

        return $deleted;
    }

    /**
     * The row-yielding half of {@see streamTable()} — split out so the identifier
     * guard above runs on call rather than on first iteration.
     *
     * @param list<string> $generated
     *
     * @return iterable<array<string, mixed>>
     */
    private function streamRows(string $table, array $generated): iterable
    {
        foreach ($this->connection->iterateAssociative(sprintf('SELECT * FROM %s', $table)) as $row) {
            foreach ($generated as $column) {
                unset($row[$column]);
            }

            yield $row;
        }
    }

    /**
     * @param list<string> $keyColumns
     */
    private function assertKeyColumns(string $table, array $keyColumns): void
    {
        if ([] === $keyColumns) {
            throw new BackupException(sprintf('Composite reconcile of "%s" needs at least one key column.', $table));
        }
        foreach ($keyColumns as $column) {
            $this->assertColumnName($column);
        }
    }

    private function assertTableName(string $table): void
    {
        if (1 !== preg_match(self::IDENTIFIER_PATTERN, $table)) {
            throw new BackupException(sprintf('Refusing to back up table with unsafe identifier "%s".', $table));
        }
    }

    private function assertColumnName(string $column): void
    {
        if (1 !== preg_match(self::IDENTIFIER_PATTERN, $column)) {
            throw new BackupException(sprintf('Refusing to reconcile with unsafe column identifier "%s".', $column));
        }
    }

    /**
     * The DB-generated columns of `$table` (ANSI `information_schema`, so it works
     * on Postgres + MySQL). Platforms without `information_schema` (e.g. SQLite)
     * throw — treat that as "no generated columns" rather than failing the backup.
     *
     * @return list<string>
     */
    private function generatedColumns(string $table): array
    {
        try {
            $names = $this->connection->fetchFirstColumn(
                'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND is_generated = ?',
                [$table, 'ALWAYS'],
            );
        } catch (Throwable) {
            return [];
        }

        $columns = [];
        foreach ($names as $name) {
            if (is_string($name)) {
                $columns[] = $name;
            }
        }

        return $columns;
    }
}
