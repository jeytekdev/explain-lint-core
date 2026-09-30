<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Tests\Unit\Rules;

use Jeytekdev\ExplainLint\Adapter\PlanFinding;
use Jeytekdev\ExplainLint\Config\Config;
use Jeytekdev\ExplainLint\Engine\ExplainOutcome;
use Jeytekdev\ExplainLint\ReasonCode;
use Jeytekdev\ExplainLint\Recorder\CapturedQuery;
use Jeytekdev\ExplainLint\Recorder\QueryPhase;
use Jeytekdev\ExplainLint\Rules\RuleEngine;
use Jeytekdev\ExplainLint\Severity;
use PHPUnit\Framework\TestCase;

final class RuleEngineTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
    }

    public function testFullTableScanOnTinyTableIsSuppressed(): void
    {
        $verdict = $this->evaluate(
            findings: [$this->finding('roles', ReasonCode::FullTableScan, hasSelectivePredicate: true)],
            rowCounts: ['roles' => 5],
        );

        self::assertTrue($verdict->passed);
        self::assertSame([], $verdict->violations);
    }

    public function testFullTableScanOnSmallTableWithoutSelectivePredicateIsSuppressed(): void
    {
        $verdict = $this->evaluate(
            findings: [$this->finding('accounts', ReasonCode::FullTableScan, hasSelectivePredicate: false)],
            rowCounts: ['accounts' => 500],
        );

        self::assertTrue($verdict->passed);
    }

    public function testFullTableScanOnSmallTableWithSelectivePredicateIsReported(): void
    {
        $verdict = $this->evaluate(
            findings: [$this->finding('accounts', ReasonCode::FullTableScan, hasSelectivePredicate: true)],
            rowCounts: ['accounts' => 500],
        );

        self::assertFalse($verdict->passed);
        self::assertCount(1, $verdict->violations);
        self::assertSame(Severity::Error, $verdict->violations[0]->severity);
    }

    public function testFullTableScanOnLargeTableIsReportedEvenWithoutSelectivePredicate(): void
    {
        $verdict = $this->evaluate(
            findings: [$this->finding('events', ReasonCode::FullTableScan, hasSelectivePredicate: false)],
            rowCounts: ['events' => 5_000_000],
        );

        self::assertFalse($verdict->passed);
    }

    public function testAllowlistedTableSuppressesViolation(): void
    {
        $verdict = $this->evaluate(
            findings: [$this->finding('audit_log', ReasonCode::FullTableScan, hasSelectivePredicate: false)],
            rowCounts: ['audit_log' => 5_000_000],
            allowlistTables: ['audit_log' => 'Intentional nightly export scan — JIRA-123'],
        );

        self::assertTrue($verdict->passed);
    }

    public function testAllowlistedFingerprintSuppressesEntireQuery(): void
    {
        $sql = 'SELECT * FROM events';
        $fingerprint = \Jeytekdev\ExplainLint\Fingerprint\SqlFingerprint::hash($sql);

        $verdict = $this->evaluate(
            findings: [$this->finding('events', ReasonCode::FullTableScan, hasSelectivePredicate: false)],
            rowCounts: ['events' => 5_000_000],
            allowlistFingerprints: [$fingerprint => 'Known report query — JIRA-456'],
            sql: $sql,
        );

        self::assertTrue($verdict->passed);
    }

    public function testIndexRejectedDefaultsToInfoSeverityWhenRuleNotConfigured(): void
    {
        $verdict = $this->evaluate(
            findings: [$this->finding('orders', ReasonCode::IndexRejected, hasSelectivePredicate: true)],
            rowCounts: ['orders' => 5_000_000],
            rules: [],
        );

        self::assertFalse($verdict->passed);
        self::assertSame(Severity::Info, $verdict->violations[0]->severity);
    }

    public function testRuleCanBeTurnedOff(): void
    {
        $verdict = $this->evaluate(
            findings: [$this->finding('orders', ReasonCode::Filesort, hasSelectivePredicate: true)],
            rowCounts: ['orders' => 5_000_000],
            rules: ['filesort' => 'off'],
        );

        self::assertTrue($verdict->passed);
    }

    public function testIgnoredQueryPatternSuppressesEverything(): void
    {
        $verdict = $this->evaluate(
            findings: [$this->finding('orders', ReasonCode::FullTableScan, hasSelectivePredicate: true)],
            rowCounts: ['orders' => 5_000_000],
            sql: 'CREATE TABLE orders (id INT)',
            ignoreQueries: ['/^\s*CREATE\s/i'],
        );

        self::assertTrue($verdict->passed);
    }

    public function testGlobalWarnModeProducesWarningSeverityByDefault(): void
    {
        $verdict = $this->evaluate(
            findings: [$this->finding('orders', ReasonCode::TemporaryTable, hasSelectivePredicate: true)],
            rowCounts: ['orders' => 5_000_000],
            mode: 'warn',
            rules: [],
        );

        self::assertFalse($verdict->passed);
        self::assertSame(Severity::Warning, $verdict->violations[0]->severity);
    }

    private function finding(string $table, ReasonCode $reasonCode, bool $hasSelectivePredicate): PlanFinding
    {
        return new PlanFinding($table, $reasonCode, ['table' => $table], $hasSelectivePredicate, 100);
    }

    /**
     * @param list<PlanFinding> $findings
     * @param array<string, int> $rowCounts
     * @param array<string, string> $allowlistTables
     * @param array<string, string> $allowlistFingerprints
     * @param array<string, string> $rules
     * @param list<string> $ignoreQueries
     */
    private function evaluate(
        array $findings,
        array $rowCounts,
        array $allowlistTables = [],
        array $allowlistFingerprints = [],
        array $rules = ['full_table_scan' => 'strict', 'no_index_available' => 'strict'],
        array $ignoreQueries = [],
        string $mode = 'strict',
        string $sql = 'SELECT * FROM t',
    ): \Jeytekdev\ExplainLint\Verdict {
        $config = Config::fromArray([
            'mode' => $mode,
            'connections' => [
                'default' => [
                    'driver' => 'mysql',
                    'rules' => $rules,
                    'row_estimate_threshold' => 1000,
                    'table_size_tiers' => ['tiny' => 100, 'small' => 1000],
                ],
            ],
            'allowlist' => $allowlistTables,
            'allowlist_fingerprints' => $allowlistFingerprints,
            'ignore_queries' => $ignoreQueries,
        ]);

        $ruleEngine = new RuleEngine($config, new FixedRowCountResolver($rowCounts));

        $query = new CapturedQuery($sql, [], $this->pdo, 'default', QueryPhase::Test);
        $adapter = new FakeAdapter($findings);
        $outcome = ExplainOutcome::analyzed('mysql', $sql, $adapter, []);
        $fingerprint = \Jeytekdev\ExplainLint\Fingerprint\SqlFingerprint::hash($sql);

        return $ruleEngine->evaluate($query, $outcome, $fingerprint);
    }
}
