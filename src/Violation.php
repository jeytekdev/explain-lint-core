<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint;

/**
 * A single structural problem found in a query's EXPLAIN plan.
 */
final class Violation
{
    /**
     * @param list<string> $tables Table names read back from the EXPLAIN plan itself.
     * @param array<string, mixed> $evidence Raw EXPLAIN row(s)/plan node(s) that triggered this violation.
     */
    public function __construct(
        public readonly string $fingerprint,
        public readonly string $normalizedSql,
        public readonly array $tables,
        public readonly ReasonCode $reasonCode,
        public readonly Severity $severity,
        public readonly array $evidence,
        public readonly string $engine,
        public readonly string $connectionName,
        public readonly ?string $message = null,
    ) {
    }

    public function describe(): string
    {
        if ($this->message !== null) {
            return $this->message;
        }

        $tables = implode(', ', $this->tables) ?: 'unknown table';

        return match ($this->reasonCode) {
            ReasonCode::FullTableScan => "Full table scan on {$tables}",
            ReasonCode::NoIndexAvailable => "No index available for query on {$tables}",
            ReasonCode::IndexRejected => "Optimizer rejected an available index on {$tables}",
            ReasonCode::Filesort => "Query on {$tables} requires filesort",
            ReasonCode::TemporaryTable => "Query on {$tables} requires a temporary table",
            ReasonCode::HighRowEstimate => "Query on {$tables} has a high estimated row count",
        };
    }

    /**
     * Actionable suggestion for fixing this specific violation, independent
     * of any custom `$message` override (which only affects `describe()`).
     */
    public function recommendation(): string
    {
        return $this->reasonCode->recommendation();
    }
}
