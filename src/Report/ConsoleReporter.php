<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Report;

use Jeytekdev\ExplainLint\Violation;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Deliberately not a grid Symfony\Component\Console\Helper\Table: a
 * violation naturally needs ~6 fields (test, query, table, issue, rows,
 * fingerprint) and a fully-qualified test name plus a full query easily
 * blow past any reasonable terminal width once laid out as columns, which
 * either truncates everything into uselessness or wraps every cell into an
 * unreadable mess. A block per violation (test name once, then indented
 * key/value lines), closer to how PHPUnit prints its own failures, scales
 * to long names/queries without a fixed column budget.
 */
final class ConsoleReporter
{
    public function report(ResultCollector $results, OutputInterface $output): void
    {
        if ($results->all() === []) {
            $output->writeln('<info>explain-lint: no query regressions found.</info>');

            return;
        }

        $width = max((new Terminal())->getWidth(), 60);

        $output->writeln('');
        $output->writeln(sprintf(
            '<comment>explain-lint</comment> found %d issue(s) in %d test(s):',
            $results->violationCount(),
            count($results->all())
        ));

        foreach ($results->all() as $outcome) {
            $output->writeln('');
            $output->writeln('<fg=white;options=bold>' . $outcome->testName . '</>');

            foreach ($outcome->violations as $violation) {
                $this->renderViolation($violation, $output, $width);
            }
        }

        $output->writeln('');
    }

    private function renderViolation(Violation $violation, OutputInterface $output, int $width): void
    {
        $output->writeln(sprintf(
            '  %s %s',
            $this->formatSeverity($violation->severity->value),
            $violation->describe()
        ));

        $indent = '      ';
        $rows = $violation->evidence['rows'] ?? $violation->evidence['Plan Rows'] ?? '-';

        $output->writeln($indent . '<fg=default>table:</>       ' . implode(', ', $violation->tables));
        $output->writeln($indent . '<fg=default>rows:</>        ' . $rows);
        $output->writeln($this->wrap($indent . '<fg=default>query:</>       ', $violation->normalizedSql, $indent, $width));
        $output->writeln($this->wrap($indent . '<fg=default>hint:</>        ', $violation->recommendation(), $indent, $width));
        // Copy into config `allowlist_fingerprints` (with a reason) to silence this specific query shape.
        $output->writeln($indent . '<fg=default>fingerprint:</> ' . $violation->fingerprint);
        $output->writeln('');
    }

    private function wrap(string $label, string $value, string $indent, int $width): string
    {
        $continuationIndent = $indent . str_repeat(' ', 15);
        $available = max($width - strlen($continuationIndent), 20);
        $wrapped = wordwrap($value, $available, "\n", true);
        $lines = explode("\n", $wrapped);

        $first = array_shift($lines);
        $out = $label . $first;

        foreach ($lines as $line) {
            $out .= "\n" . $continuationIndent . $line;
        }

        return $out;
    }

    private function formatSeverity(string $severity): string
    {
        return match ($severity) {
            'error' => '<fg=red;options=bold>[error]</>',
            'warning' => '<fg=yellow;options=bold>[warning]</>',
            default => '<fg=blue;options=bold>[info]</>',
        };
    }
}
