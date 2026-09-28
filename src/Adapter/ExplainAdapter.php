<?php

declare(strict_types=1);

namespace ExplainLint\Adapter;

/**
 * One implementation per database engine. An adapter knows how to run a
 * flat EXPLAIN for its engine and how to turn the raw plan into candidate
 * PlanFinding objects — it does not know about config, tiering or
 * allowlisting, which live in RuleEngine.
 */
interface ExplainAdapter
{
    /**
     * @return list<string> PDO::ATTR_DRIVER_NAME values this adapter handles, e.g. ['mysql'].
     */
    public function driverNames(): array;

    /**
     * Runs EXPLAIN for the given already-literal (parameters substituted) SQL
     * on the given connection, and returns the raw plan in adapter-specific shape.
     *
     * @return array<string, mixed>
     */
    public function explain(\PDO $connection, string $renderedSql): array;

    /**
     * @param array<string, mixed> $plan As returned by explain().
     * @return list<PlanFinding>
     */
    public function analyze(array $plan, int $rowEstimateThreshold): array;

    /**
     * Table names involved in the plan, read from the plan itself
     * (not parsed from SQL) so RuleEngine can resolve table-size tiers.
     *
     * @param array<string, mixed> $plan
     * @return list<string>
     */
    public function tablesInPlan(array $plan): array;
}
