<?php

declare(strict_types=1);

namespace ExplainLint\Tests\Unit\Adapter;

use ExplainLint\Adapter\MySqlAdapter;
use ExplainLint\ReasonCode;
use PHPUnit\Framework\TestCase;

final class MySqlAdapterTest extends TestCase
{
    private MySqlAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new MySqlAdapter();
    }

    public function testFullTableScanIsDetected(): void
    {
        $plan = $this->plan([
            'table' => 'users',
            'type' => 'ALL',
            'possible_keys' => null,
            'key' => null,
            'extra' => '',
            'rows' => '5000',
        ]);

        $findings = $this->adapter->analyze($plan, 1000);
        $reasons = array_map(static fn ($f) => $f->reasonCode, $findings);

        self::assertContains(ReasonCode::FullTableScan, $reasons);
        self::assertContains(ReasonCode::NoIndexAvailable, $reasons);
    }

    public function testIndexRejectedIsInfoNotErrorCandidate(): void
    {
        $plan = $this->plan([
            'table' => 'orders',
            'type' => 'ALL',
            'possible_keys' => 'idx_status',
            'key' => null,
            'extra' => 'Using where',
            'rows' => '200',
        ]);

        $findings = $this->adapter->analyze($plan, 1000);
        $reasons = array_map(static fn ($f) => $f->reasonCode, $findings);

        self::assertContains(ReasonCode::IndexRejected, $reasons);
        self::assertNotContains(ReasonCode::NoIndexAvailable, $reasons);
    }

    public function testUsedIndexProducesNoScanRelatedFinding(): void
    {
        $plan = $this->plan([
            'table' => 'orders',
            'type' => 'ref',
            'possible_keys' => 'idx_status',
            'key' => 'idx_status',
            'extra' => 'Using where',
            'rows' => '5',
        ]);

        $findings = $this->adapter->analyze($plan, 1000);
        $reasons = array_map(static fn ($f) => $f->reasonCode, $findings);

        self::assertNotContains(ReasonCode::FullTableScan, $reasons);
        self::assertNotContains(ReasonCode::NoIndexAvailable, $reasons);
        self::assertNotContains(ReasonCode::IndexRejected, $reasons);
    }

    public function testFilesortIsDetected(): void
    {
        $plan = $this->plan([
            'table' => 'orders',
            'type' => 'ref',
            'possible_keys' => 'idx_status',
            'key' => 'idx_status',
            'extra' => 'Using where; Using filesort',
            'rows' => '5',
        ]);

        $reasons = array_map(static fn ($f) => $f->reasonCode, $this->adapter->analyze($plan, 1000));

        self::assertContains(ReasonCode::Filesort, $reasons);
    }

    public function testTemporaryTableIsDetected(): void
    {
        $plan = $this->plan([
            'table' => 'orders',
            'type' => 'ref',
            'possible_keys' => 'idx_status',
            'key' => 'idx_status',
            'extra' => 'Using temporary; Using filesort',
            'rows' => '5',
        ]);

        $reasons = array_map(static fn ($f) => $f->reasonCode, $this->adapter->analyze($plan, 1000));

        self::assertContains(ReasonCode::TemporaryTable, $reasons);
    }

    public function testHighRowEstimateIsDetectedAboveThreshold(): void
    {
        $plan = $this->plan([
            'table' => 'orders',
            'type' => 'ref',
            'possible_keys' => 'idx_status',
            'key' => 'idx_status',
            'extra' => 'Using where',
            'rows' => '50000',
        ]);

        $reasons = array_map(static fn ($f) => $f->reasonCode, $this->adapter->analyze($plan, 1000));

        self::assertContains(ReasonCode::HighRowEstimate, $reasons);
    }

    public function testInsertTargetRowProducesNoFindings(): void
    {
        $plan = $this->plan([
            'select_type' => 'INSERT',
            'table' => 'el_posts',
            'type' => 'ALL',
            'possible_keys' => null,
            'key' => null,
            'extra' => '',
            'rows' => '5000',
        ]);

        self::assertSame([], $this->adapter->analyze($plan, 1000));
    }

    public function testInsertSelectSourceRowIsStillAnalyzed(): void
    {
        $plan = [
            'rows' => [
                ['select_type' => 'INSERT', 'table' => 'orders_archive', 'type' => 'ALL', 'possible_keys' => null, 'key' => null, 'extra' => '', 'rows' => '1'],
                ['select_type' => 'SIMPLE', 'table' => 'orders', 'type' => 'ALL', 'possible_keys' => null, 'key' => null, 'extra' => '', 'rows' => '5000'],
            ],
        ];

        $findings = $this->adapter->analyze($plan, 1000);
        $tables = array_map(static fn ($f) => $f->table, $findings);

        self::assertNotContains('orders_archive', $tables);
        self::assertContains('orders', $tables);
    }

    public function testDerivedTablePseudoNamesAreIgnored(): void
    {
        $plan = $this->plan([
            'table' => '<derived2>',
            'type' => 'ALL',
            'possible_keys' => null,
            'key' => null,
            'extra' => '',
            'rows' => '5000',
        ]);

        self::assertSame([], $this->adapter->analyze($plan, 1000));
        self::assertSame([], $this->adapter->tablesInPlan($plan));
    }

    public function testTablesInPlanExtractsUniqueRealTableNames(): void
    {
        $plan = [
            'rows' => [
                ['table' => 'users', 'type' => 'ALL', 'possible_keys' => null, 'key' => null, 'extra' => '', 'rows' => '10'],
                ['table' => 'orders', 'type' => 'ref', 'possible_keys' => 'idx', 'key' => 'idx', 'extra' => '', 'rows' => '10'],
            ],
        ];

        self::assertSame(['users', 'orders'], $this->adapter->tablesInPlan($plan));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{rows: list<array<string, mixed>>}
     */
    private function plan(array $row): array
    {
        return ['rows' => [$row]];
    }
}
