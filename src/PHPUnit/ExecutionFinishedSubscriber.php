<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\PHPUnit;

use Jeytekdev\ExplainLint\Config\Config;
use Jeytekdev\ExplainLint\Report\ConsoleReporter;
use Jeytekdev\ExplainLint\Report\GithubAnnotationsReporter;
use Jeytekdev\ExplainLint\Report\JUnitReporter;
use Jeytekdev\ExplainLint\Report\ResultCollector;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber as ExecutionFinishedSubscriberInterface;
use Symfony\Component\Console\Output\ConsoleOutput;

final class ExecutionFinishedSubscriber implements ExecutionFinishedSubscriberInterface
{
    public function __construct(
        private readonly ResultCollector $results,
        private readonly Config $config,
    ) {
    }

    public function notify(ExecutionFinished $event): void
    {
        // PHPUnit/Pest keep doing their own output work (result summaries,
        // Pest's grouped per-file printer, ...) after this event fires —
        // there is no guarantee we're the last subscriber for it. A shutdown
        // function is the one place in a CLI PHP process guaranteed to run
        // after everything else, including whatever PHPUnit/Pest print on
        // their way out (and after PHPUnit's own exit() call, if any —
        // shutdown functions still run first regardless of how exit() was
        // reached), so the report always lands as the true last thing printed.
        register_shutdown_function(function (): void {
            $this->render();
        });
    }

    private function render(): void
    {
        if ($this->config->report['console']) {
            (new ConsoleReporter())->report($this->results, new ConsoleOutput());
        }

        $junitPath = $this->config->report['junit'];
        if (is_string($junitPath) && $junitPath !== '') {
            (new JUnitReporter())->write($this->results, $junitPath);
        }

        $githubReporter = new GithubAnnotationsReporter();
        if ($githubReporter->shouldRun((string) $this->config->report['github_annotations'])) {
            $githubReporter->report($this->results);
        }

        // Strict-mode build-breaking. This exit() call is the primary
        // enforcement mechanism, but it depends on PHPUnit not swallowing
        // process exit codes set here — validate this against a real PHPUnit
        // run early, and treat `explain-lint:check` (reading the JUnit
        // report as a second CI step) as the reliable fallback either way.
        if ($this->config->mode === 'strict' && $this->results->hasErrors()) {
            exit(1);
        }
    }
}
