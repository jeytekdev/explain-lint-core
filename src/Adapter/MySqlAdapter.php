<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Adapter;

use Jeytekdev\ExplainLint\ReasonCode;

/**
 * Parses the classic tabular `EXPLAIN` output (type, possible_keys, key,
 * Extra, rows). Deliberately not `EXPLAIN FORMAT=JSON`: the tabular format
 * has been stable since MySQL 5.6 and is identical on MariaDB, whereas the
 * JSON format's schema has diverged between MySQL 8.3+ and MariaDB.
 */
final class MySqlAdapter implements ExplainAdapter
{
    public function driverNames(): array
    {
        return ['mysql'];
    }

    public function explain(\PDO $connection, string $renderedSql): array
    {
        $statement = $connection->query('EXPLAIN ' . $renderedSql);
        $rows = $statement !== false ? $statement->fetchAll(\PDO::FETCH_ASSOC) : [];

        // Normalize column casing: MySQL/MariaDB versions differ on `Extra` vs `extra`.
        $normalized = [];
        foreach ($rows as $row) {
            $normalizedRow = [];
            foreach ($row as $key => $value) {
                $normalizedRow[strtolower($key)] = $value;
            }
            $normalized[] = $normalizedRow;
        }

        return ['rows' => $normalized];
    }

    public function analyze(array $plan, int $rowEstimateThreshold): array
    {
        $findings = [];

        foreach ($plan['rows'] ?? [] as $row) {
            $table = (string) ($row['table'] ?? '');
            if ($table === '' || str_starts_with($table, '<')) {
                // `<derived2>`, `<union1,2>`, etc. — not a real table, no tiering possible.
                continue;
            }

            if (strtolower((string) ($row['select_type'] ?? '')) === 'insert') {
                // The write target of an `INSERT` — always shows type=ALL and
                // possible_keys=NULL because it isn't being scanned/read, it's
                // being written to. For `INSERT ... SELECT`, the actual source
                // table gets its own row with a different select_type and is
                // still analyzed normally below.
                continue;
            }

            $type = (string) ($row['type'] ?? '');
            $possibleKeys = $row['possible_keys'] ?? null;
            $key = $row['key'] ?? null;
            $extra = (string) ($row['extra'] ?? '');
            $rows = $row['rows'] ?? null;
            $rowEstimate = $rows !== null ? (int) $rows : null;
            $hasSelectivePredicate = stripos($extra, 'Using where') !== false;

            if ($type === 'ALL') {
                $findings[] = new PlanFinding($table, ReasonCode::FullTableScan, $row, $hasSelectivePredicate, $rowEstimate);
            }

            if ($possibleKeys === null || $possibleKeys === '') {
                $findings[] = new PlanFinding($table, ReasonCode::NoIndexAvailable, $row, $hasSelectivePredicate, $rowEstimate);
            } elseif ($key === null || $key === '') {
                // Optimizer had a usable index and chose not to use it — often a
                // correct decision for low-selectivity predicates, so this stays Info.
                $findings[] = new PlanFinding($table, ReasonCode::IndexRejected, $row, $hasSelectivePredicate, $rowEstimate);
            }

            if (stripos($extra, 'Using filesort') !== false) {
                $findings[] = new PlanFinding($table, ReasonCode::Filesort, $row, $hasSelectivePredicate, $rowEstimate);
            }

            if (stripos($extra, 'Using temporary') !== false) {
                $findings[] = new PlanFinding($table, ReasonCode::TemporaryTable, $row, $hasSelectivePredicate, $rowEstimate);
            }

            if ($rowEstimate !== null && $rowEstimate > $rowEstimateThreshold) {
                $findings[] = new PlanFinding($table, ReasonCode::HighRowEstimate, $row, $hasSelectivePredicate, $rowEstimate);
            }
        }

        return $findings;
    }

    public function tablesInPlan(array $plan): array
    {
        $tables = [];
        foreach ($plan['rows'] ?? [] as $row) {
            $table = (string) ($row['table'] ?? '');
            if ($table !== '' && !str_starts_with($table, '<')) {
                $tables[$table] = true;
            }
        }

        return array_keys($tables);
    }
}
