<?php

declare(strict_types=1);

namespace ExplainLint\Tests\Unit\Adapter;

use ExplainLint\Adapter\PostgresAdapter;
use ExplainLint\ReasonCode;
use PHPUnit\Framework\TestCase;

final class PostgresAdapterTest extends TestCase
{
    private PostgresAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new PostgresAdapter();
    }

    public function testSeqScanWithFilterIsEscalatedAsSelective(): void
    {
        $plan = ['tree' => [
            'Node Type' => 'Seq Scan',
            'Relation Name' => 'users',
            'Filter' => '(age > 30)',
            'Plan Rows' => 500,
        ]];

        $findings = $this->adapter->analyze($plan, 100000);

        self::assertCount(1, $findings);
        self::assertSame(ReasonCode::FullTableScan, $findings[0]->reasonCode);
        self::assertTrue($findings[0]->hasSelectivePredicate);
    }

    public function testSeqScanWithoutFilterIsReportedAsNonSelective(): void
    {
        $plan = ['tree' => [
            'Node Type' => 'Seq Scan',
            'Relation Name' => 'status_lookup',
            'Plan Rows' => 5,
        ]];

        $findings = $this->adapter->analyze($plan, 100000);

        self::assertCount(1, $findings);
        self::assertSame(ReasonCode::FullTableScan, $findings[0]->reasonCode);
        self::assertFalse($findings[0]->hasSelectivePredicate);
    }

    public function testIndexScanProducesNoViolation(): void
    {
        $plan = ['tree' => [
            'Node Type' => 'Index Scan',
            'Relation Name' => 'users',
            'Index Cond' => '(id = 1)',
            'Plan Rows' => 1,
        ]];

        self::assertSame([], $this->adapter->analyze($plan, 100000));
    }

    public function testBitmapHeapScanProducesNoViolation(): void
    {
        $plan = ['tree' => [
            'Node Type' => 'Bitmap Heap Scan',
            'Relation Name' => 'orders',
            'Plan Rows' => 42,
        ]];

        self::assertSame([], $this->adapter->analyze($plan, 100000));
    }

    public function testHighRowEstimateIsDetectedRecursively(): void
    {
        $plan = ['tree' => [
            'Node Type' => 'Hash Join',
            'Plan Rows' => 10,
            'Plans' => [
                [
                    'Node Type' => 'Index Scan',
                    'Relation Name' => 'orders',
                    'Plan Rows' => 500000,
                ],
                [
                    'Node Type' => 'Seq Scan',
                    'Relation Name' => 'status_lookup',
                    'Plan Rows' => 5,
                ],
            ],
        ]];

        $findings = $this->adapter->analyze($plan, 1000);
        $ordersFindings = array_filter($findings, static fn ($f) => $f->table === 'orders');
        $reasons = array_map(static fn ($f) => $f->reasonCode, $ordersFindings);

        self::assertContains(ReasonCode::HighRowEstimate, $reasons);
        self::assertNotContains(ReasonCode::FullTableScan, $reasons, 'Index Scan must never produce FullTableScan');
    }

    public function testTablesInPlanCollectsRelationNamesRecursively(): void
    {
        $plan = ['tree' => [
            'Node Type' => 'Hash Join',
            'Plans' => [
                ['Node Type' => 'Seq Scan', 'Relation Name' => 'orders'],
                ['Node Type' => 'Index Scan', 'Relation Name' => 'users'],
            ],
        ]];

        self::assertSame(['orders', 'users'], $this->adapter->tablesInPlan($plan));
    }
}
