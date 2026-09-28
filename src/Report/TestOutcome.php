<?php

declare(strict_types=1);

namespace ExplainLint\Report;

use ExplainLint\Violation;

final class TestOutcome
{
    /**
     * @param list<Violation> $violations
     */
    public function __construct(
        public readonly string $testId,
        public readonly string $testName,
        public readonly array $violations,
    ) {
    }
}
