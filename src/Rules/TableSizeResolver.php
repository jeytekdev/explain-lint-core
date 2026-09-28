<?php

declare(strict_types=1);

namespace ExplainLint\Rules;

/**
 * Approximate row counts per table, cached for the lifetime of the test
 * run so tiering doesn't re-query information_schema/pg_class on every
 * single captured query.
 */
final class TableSizeResolver implements RowCountResolver
{
    /** @var array<string, int> */
    private array $cache = [];

    public function rowCountFor(\PDO $connection, string $driver, string $connectionName, string $table): int
    {
        $key = $connectionName . '::' . $table;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $count = match ($driver) {
            'mysql' => $this->mysqlRowCount($connection, $table),
            'pgsql' => $this->pgsqlRowCount($connection, $table),
            default => PHP_INT_MAX,
        };

        return $this->cache[$key] = $count;
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    private function mysqlRowCount(\PDO $connection, string $table): int
    {
        $statement = $connection->prepare(
            'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->execute(['table' => $table]);
        $value = $statement->fetchColumn();

        // Unknown table (or the estimate genuinely couldn't be read): fail open —
        // treat as unbounded so scan rules are NOT silently suppressed.
        return $value !== false && $value !== null ? (int) $value : PHP_INT_MAX;
    }

    private function pgsqlRowCount(\PDO $connection, string $table): int
    {
        $statement = $connection->prepare('SELECT reltuples::bigint AS estimate FROM pg_class WHERE relname = :table');
        $statement->execute(['table' => $table]);
        $estimate = $statement->fetchColumn();

        if ($estimate !== false && (int) $estimate > 0) {
            return (int) $estimate;
        }

        // reltuples is 0/-1 until the table has been ANALYZE'd, which freshly
        // migrated test databases usually haven't been — fall back to the
        // live tuple counter tracked by autovacuum.
        $statement = $connection->prepare('SELECT n_live_tup FROM pg_stat_user_tables WHERE relname = :table');
        $statement->execute(['table' => $table]);
        $liveTuples = $statement->fetchColumn();

        if ($liveTuples !== false && (int) $liveTuples > 0) {
            return (int) $liveTuples;
        }

        // Last resort: exact count. Safe to run — it's a read-only SELECT COUNT(*)
        // and only happens once per (connection, table) per test run.
        try {
            $quotedTable = '"' . str_replace('"', '""', $table) . '"';
            $statement = $connection->query('SELECT COUNT(*) FROM ' . $quotedTable);
            $exact = $statement !== false ? $statement->fetchColumn() : false;

            return $exact !== false ? (int) $exact : PHP_INT_MAX;
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }
    }
}
