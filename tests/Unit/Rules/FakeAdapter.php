<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Tests\Unit\Rules;

use Jeytekdev\ExplainLint\Adapter\ExplainAdapter;
use Jeytekdev\ExplainLint\Adapter\PlanFinding;

final class FakeAdapter implements ExplainAdapter
{
    /**
     * @param list<PlanFinding> $findings
     */
    public function __construct(private readonly array $findings)
    {
    }

    public function driverNames(): array
    {
        return ['mysql'];
    }

    public function explain(\PDO $connection, string $renderedSql): array
    {
        return [];
    }

    public function analyze(array $plan, int $rowEstimateThreshold): array
    {
        return $this->findings;
    }

    public function tablesInPlan(array $plan): array
    {
        return array_values(array_unique(array_map(static fn (PlanFinding $f) => $f->table, $this->findings)));
    }
}
