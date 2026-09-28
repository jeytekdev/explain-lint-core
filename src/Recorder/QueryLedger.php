<?php

declare(strict_types=1);

namespace ExplainLint\Recorder;

/**
 * Tracks which query fingerprints have already been sent through EXPLAIN
 * during the current run. EXPLAIN itself is cheap, but a suite that calls
 * the same query shape hundreds of times (e.g. inside a loop-based factory)
 * would otherwise re-analyze it every time for no new information — the
 * ledger caps analysis to the first occurrence per fingerprint per run.
 *
 * Unlike QueryRecorder (reset per test), the ledger's lifetime is the whole
 * test run: it is created once by ExplainLintExtension::bootstrap() and
 * never reset until the process ends.
 */
final class QueryLedger
{
    /** @var array<string, true> */
    private array $seenFingerprints = [];

    public function shouldAnalyze(string $fingerprint): bool
    {
        if (isset($this->seenFingerprints[$fingerprint])) {
            return false;
        }

        $this->seenFingerprints[$fingerprint] = true;

        return true;
    }

    public function reset(): void
    {
        $this->seenFingerprints = [];
    }
}
