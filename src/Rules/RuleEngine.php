<?php

declare(strict_types=1);

namespace ExplainLint\Rules;

use ExplainLint\Adapter\PlanFinding;
use ExplainLint\Config\Config;
use ExplainLint\Config\ConnectionConfig;
use ExplainLint\Engine\ExplainOutcome;
use ExplainLint\Fingerprint\SqlFingerprint;
use ExplainLint\ReasonCode;
use ExplainLint\Recorder\CapturedQuery;
use ExplainLint\Verdict;
use ExplainLint\Violation;

/**
 * Turns raw adapter findings into a Verdict, applying the false-positive
 * filters that make the tool tolerable to run on a real codebase:
 * ignore rules, allowlisting, and table-size tiering.
 */
final class RuleEngine
{
    private const SCAN_REASON_CODES = [
        ReasonCode::FullTableScan,
        ReasonCode::NoIndexAvailable,
        ReasonCode::IndexRejected,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly RowCountResolver $tableSizeResolver = new TableSizeResolver(),
    ) {
    }

    public function evaluate(CapturedQuery $query, ExplainOutcome $outcome, string $fingerprint): Verdict
    {
        if (!$outcome->supported || $outcome->adapter === null) {
            return Verdict::pass($outcome->plan);
        }

        if ($this->config->isIgnoredQuery($query->sql) || $this->config->isIgnoredPath($query->callerFile)) {
            return Verdict::pass($outcome->plan);
        }

        if ($this->config->allowlistReasonForFingerprint($fingerprint) !== null) {
            return Verdict::pass($outcome->plan);
        }

        $connectionConfig = $this->config->connection($query->connectionName);
        $findings = $outcome->adapter->analyze($outcome->plan, $connectionConfig->rowEstimateThreshold);

        $violations = [];
        foreach ($findings as $finding) {
            $violation = $this->evaluateFinding($query, $outcome, $finding, $fingerprint, $connectionConfig);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return Verdict::fail($violations, $outcome->plan);
    }

    private function evaluateFinding(
        CapturedQuery $query,
        ExplainOutcome $outcome,
        PlanFinding $finding,
        string $fingerprint,
        ConnectionConfig $connectionConfig
    ): ?Violation {
        if ($this->config->allowlistReasonForTable($finding->table) !== null) {
            return null;
        }

        $severity = $this->config->severityFor($query->connectionName, $finding->reasonCode);
        if ($severity === null) {
            return null;
        }

        if (in_array($finding->reasonCode, self::SCAN_REASON_CODES, true)) {
            $tier = $this->resolveTier($query, $outcome->driver, $finding->table, $connectionConfig);

            if ($tier === 'tiny') {
                return null;
            }

            if ($tier === 'small' && !$finding->hasSelectivePredicate) {
                return null;
            }
        }

        return new Violation(
            fingerprint: $fingerprint,
            normalizedSql: SqlFingerprint::normalize($query->sql),
            tables: [$finding->table],
            reasonCode: $finding->reasonCode,
            severity: $severity,
            evidence: $finding->evidence,
            engine: $outcome->driver,
            connectionName: $query->connectionName,
        );
    }

    /**
     * @return 'tiny'|'small'|'medium_or_large'
     */
    private function resolveTier(CapturedQuery $query, string $driver, string $table, ConnectionConfig $connectionConfig): string
    {
        $rowCount = $this->tableSizeResolver->rowCountFor($query->connection, $driver, $query->connectionName, $table);

        if ($rowCount <= $connectionConfig->tableSizeTiers['tiny']) {
            return 'tiny';
        }

        if ($rowCount <= $connectionConfig->tableSizeTiers['small']) {
            return 'small';
        }

        return 'medium_or_large';
    }
}
