<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint;

/**
 * The outcome of running one captured query through the RuleEngine.
 */
final class Verdict
{
    /**
     * @param list<Violation> $violations
     * @param array<string, mixed> $planSnapshot Raw EXPLAIN output, kept for reporting even when there are no violations.
     */
    public function __construct(
        public readonly bool $passed,
        public readonly array $violations,
        public readonly array $planSnapshot,
    ) {
    }

    public static function pass(array $planSnapshot): self
    {
        return new self(true, [], $planSnapshot);
    }

    /**
     * @param list<Violation> $violations
     */
    public static function fail(array $violations, array $planSnapshot): self
    {
        return new self($violations === [], $violations, $planSnapshot);
    }
}
