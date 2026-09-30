<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Adapter;

use Jeytekdev\ExplainLint\ReasonCode;

/**
 * Parses `EXPLAIN (FORMAT JSON)` output and walks the recursive `Plans[]`
 * tree.
 *
 * - `Seq Scan` with a `Filter` means a predicate exists but no index was
 *   used — reported as FullTableScan with a selective predicate, so
 *   RuleEngine's table-size tiering (which requires a selective predicate
 *   on "small" tables) applies exactly as it does for MySQL.
 * - `Seq Scan` without a `Filter` is an unconditional full read, which is
 *   frequently intentional (e.g. `SELECT * FROM small_lookup_table`) — it is
 *   still reported, but with hasSelectivePredicate=false, so tiering
 *   suppresses it on tiny/small tables the same way.
 * - `Index Scan`, `Index Only Scan` and `Bitmap Heap Scan` never produce a
 *   violation.
 */
final class PostgresAdapter implements ExplainAdapter
{
    private const NO_VIOLATION_NODE_TYPES = ['Index Scan', 'Index Only Scan', 'Bitmap Heap Scan'];

    public function driverNames(): array
    {
        return ['pgsql'];
    }

    public function explain(\PDO $connection, string $renderedSql): array
    {
        $statement = $connection->query('EXPLAIN (FORMAT JSON) ' . $renderedSql);
        $raw = $statement !== false ? $statement->fetchColumn() : null;

        if (!is_string($raw)) {
            return ['tree' => null];
        }

        $decoded = json_decode($raw, true);
        $tree = $decoded[0]['Plan'] ?? null;

        return ['tree' => is_array($tree) ? $tree : null];
    }

    public function analyze(array $plan, int $rowEstimateThreshold): array
    {
        $tree = $plan['tree'] ?? null;
        if (!is_array($tree)) {
            return [];
        }

        $findings = [];
        $this->walk($tree, $rowEstimateThreshold, $findings);

        return $findings;
    }

    public function tablesInPlan(array $plan): array
    {
        $tree = $plan['tree'] ?? null;
        if (!is_array($tree)) {
            return [];
        }

        $tables = [];
        $this->collectTables($tree, $tables);

        return array_keys($tables);
    }

    /**
     * @param array<string, mixed> $node
     * @param list<PlanFinding> $findings
     */
    private function walk(array $node, int $rowEstimateThreshold, array &$findings): void
    {
        $nodeType = (string) ($node['Node Type'] ?? '');
        $table = (string) ($node['Relation Name'] ?? '');
        $planRows = isset($node['Plan Rows']) ? (int) $node['Plan Rows'] : null;

        if ($nodeType === 'Seq Scan' && $table !== '') {
            $hasFilter = isset($node['Filter']);
            $findings[] = new PlanFinding($table, ReasonCode::FullTableScan, $node, $hasFilter, $planRows);
        } elseif (in_array($nodeType, self::NO_VIOLATION_NODE_TYPES, true)) {
            // Explicitly no violation — an index was used.
        }

        if ($table !== '' && $planRows !== null && $planRows > $rowEstimateThreshold) {
            $hasFilter = isset($node['Filter']) || isset($node['Index Cond']);
            $findings[] = new PlanFinding($table, ReasonCode::HighRowEstimate, $node, $hasFilter, $planRows);
        }

        foreach ($node['Plans'] ?? [] as $child) {
            if (is_array($child)) {
                $this->walk($child, $rowEstimateThreshold, $findings);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true> $tables
     */
    private function collectTables(array $node, array &$tables): void
    {
        $table = (string) ($node['Relation Name'] ?? '');
        if ($table !== '') {
            $tables[$table] = true;
        }

        foreach ($node['Plans'] ?? [] as $child) {
            if (is_array($child)) {
                $this->collectTables($child, $tables);
            }
        }
    }
}
