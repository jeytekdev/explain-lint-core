<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint;

enum ReasonCode: string
{
    case FullTableScan = 'full_table_scan';
    case NoIndexAvailable = 'no_index_available';
    case IndexRejected = 'index_rejected';
    case Filesort = 'filesort';
    case TemporaryTable = 'temporary_table';
    case HighRowEstimate = 'high_row_estimate';

    /**
     * Default severity when no per-connection rule mode overrides it.
     */
    public function defaultSeverity(): Severity
    {
        return match ($this) {
            self::FullTableScan, self::NoIndexAvailable => Severity::Error,
            self::IndexRejected => Severity::Info,
            self::Filesort, self::TemporaryTable, self::HighRowEstimate => Severity::Warning,
        };
    }

    /**
     * Actionable one-liner suggesting how to fix this class of problem.
     */
    public function recommendation(): string
    {
        return match ($this) {
            self::FullTableScan => "Add an index covering the query's WHERE/JOIN/ORDER BY columns, or check why an existing index isn't used (leading wildcard LIKE, a function/cast on the column, implicit type mismatch).",
            self::NoIndexAvailable => 'No index exists that the optimizer could choose — add an index on the filtered or joined column(s).',
            self::IndexRejected => 'An index exists but the optimizer skipped it — often correct for low-selectivity predicates; verify selectivity before forcing the index.',
            self::Filesort => 'Add an index matching the ORDER BY (and any preceding WHERE/GROUP BY) columns so rows come back pre-sorted.',
            self::TemporaryTable => "Simplify the query (DISTINCT/GROUP BY/UNION on non-indexed columns) or add a covering index so the optimizer doesn't need to materialize a temp table.",
            self::HighRowEstimate => 'Narrow the result with a more selective predicate/index, or paginate/limit instead of reading the full estimated row set.',
        };
    }
}
