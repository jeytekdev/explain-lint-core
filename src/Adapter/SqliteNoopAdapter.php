<?php

declare(strict_types=1);

namespace ExplainLint\Adapter;

/**
 * SQLite is out of scope for v1 (see roadmap in README/CONTRIBUTING) — its
 * `EXPLAIN QUERY PLAN` output has a different enough shape that it deserves
 * its own rule set rather than a half-mapped reuse of the MySQL rules. This
 * adapter always passes so `pdo_sqlite`-backed test suites (e.g. Laravel's
 * default in-memory testing connection) don't get spurious failures merely
 * for using an unsupported driver.
 */
final class SqliteNoopAdapter implements ExplainAdapter
{
    public function driverNames(): array
    {
        return ['sqlite'];
    }

    public function explain(\PDO $connection, string $renderedSql): array
    {
        return ['note' => 'SQLite is not analyzed in this release; see roadmap.'];
    }

    public function analyze(array $plan, int $rowEstimateThreshold): array
    {
        return [];
    }

    public function tablesInPlan(array $plan): array
    {
        return [];
    }
}
