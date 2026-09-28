<?php

declare(strict_types=1);

namespace ExplainLint\Report;

use ExplainLint\Severity;

/**
 * Emits GitHub Actions workflow-command annotations to stdout:
 * https://docs.github.com/en/actions/using-workflows/workflow-commands-for-github-actions
 */
final class GithubAnnotationsReporter
{
    public function shouldRun(string $mode): bool
    {
        return match ($mode) {
            'always' => true,
            'never' => false,
            default => ($_SERVER['GITHUB_ACTIONS'] ?? getenv('GITHUB_ACTIONS')) === 'true',
        };
    }

    public function report(ResultCollector $results): void
    {
        foreach ($results->all() as $outcome) {
            foreach ($outcome->violations as $violation) {
                $command = match ($violation->severity) {
                    Severity::Error => 'error',
                    Severity::Warning => 'warning',
                    Severity::Info => 'notice',
                };

                $message = sprintf(
                    '[%s] %s — %s (%s)',
                    $outcome->testName,
                    $violation->describe(),
                    $violation->normalizedSql,
                    $violation->recommendation()
                );

                echo sprintf(
                    "::%s title=explain-lint::%s\n",
                    $command,
                    $this->escape($message)
                );
            }
        }
    }

    private function escape(string $value): string
    {
        return str_replace(["%", "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }
}
