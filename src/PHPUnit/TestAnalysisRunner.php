<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\PHPUnit;

use Jeytekdev\ExplainLint\Engine\ExplainRunner;
use Jeytekdev\ExplainLint\Fingerprint\SqlFingerprint;
use Jeytekdev\ExplainLint\Recorder\QueryLedger;
use Jeytekdev\ExplainLint\Recorder\QueryPhase;
use Jeytekdev\ExplainLint\Recorder\QueryRecorder;
use Jeytekdev\ExplainLint\Report\ResultCollector;
use Jeytekdev\ExplainLint\Report\TestOutcome;
use Jeytekdev\ExplainLint\Rules\RuleEngine;
use Jeytekdev\ExplainLint\Violation;

/**
 * Shared drain-and-analyze logic used by the Finished/Failed/Errored
 * subscribers — a test reaches exactly one of those three terminal events,
 * so whichever one fires is responsible for flushing this test's queries.
 */
final class TestAnalysisRunner
{
    public function __construct(
        private readonly QueryRecorder $recorder,
        private readonly QueryLedger $ledger,
        private readonly ExplainRunner $explainRunner,
        private readonly RuleEngine $ruleEngine,
        private readonly ResultCollector $results,
    ) {
    }

    public function analyze(string $testId, string $testName): void
    {
        $queries = $this->recorder->flush();

        /** @var list<Violation> $violations */
        $violations = [];

        foreach ($queries as $query) {
            // Only queries executed during the test method body itself are
            // analyzed by default — setUp()/tearDown() (migrations, seeders,
            // fixture loading) are noise for a "did this test's own queries
            // regress" check.
            if ($query->phase !== QueryPhase::Test) {
                continue;
            }

            $fingerprint = SqlFingerprint::hash($query->sql);
            if (!$this->ledger->shouldAnalyze($fingerprint)) {
                continue;
            }

            $outcome = $this->explainRunner->run($query);
            $verdict = $this->ruleEngine->evaluate($query, $outcome, $fingerprint);

            foreach ($verdict->violations as $violation) {
                $violations[] = $violation;
            }
        }

        $this->results->add(new TestOutcome($testId, $testName, $violations));
    }
}
