<?php

declare(strict_types=1);

namespace ExplainLint\Adapter;

use ExplainLint\ReasonCode;

/**
 * A raw candidate problem read directly off one EXPLAIN plan node, before
 * table-size tiering, allowlisting or severity resolution are applied by
 * the RuleEngine. Adapters only ever produce findings — they never decide
 * whether a finding should actually fail the build.
 */
final class PlanFinding
{
    /**
     * @param array<string, mixed> $evidence Raw EXPLAIN row/node that produced this finding.
     */
    public function __construct(
        public readonly string $table,
        public readonly ReasonCode $reasonCode,
        public readonly array $evidence,
        public readonly bool $hasSelectivePredicate,
        public readonly ?int $rowEstimate = null,
    ) {
    }
}
