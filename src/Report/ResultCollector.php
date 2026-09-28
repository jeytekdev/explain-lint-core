<?php

declare(strict_types=1);

namespace ExplainLint\Report;

use ExplainLint\Severity;

final class ResultCollector
{
    /** @var list<TestOutcome> */
    private array $outcomes = [];

    public function add(TestOutcome $outcome): void
    {
        if ($outcome->violations !== []) {
            $this->outcomes[] = $outcome;
        }
    }

    /**
     * @return list<TestOutcome>
     */
    public function all(): array
    {
        return $this->outcomes;
    }

    public function hasErrors(): bool
    {
        foreach ($this->outcomes as $outcome) {
            foreach ($outcome->violations as $violation) {
                if ($violation->severity === Severity::Error) {
                    return true;
                }
            }
        }

        return false;
    }

    public function violationCount(): int
    {
        $count = 0;
        foreach ($this->outcomes as $outcome) {
            $count += count($outcome->violations);
        }

        return $count;
    }
}
