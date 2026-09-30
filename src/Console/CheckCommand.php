<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Console;

use Jeytekdev\ExplainLint\Config\ConfigLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reliable fallback for strict-mode enforcement: reads the JUnit report
 * written by the previous `phpunit` run and exits non-zero if it contains
 * any Error-severity violation while the config's mode is 'strict'.
 *
 * Exists because relying solely on `exit(1)` from inside PHPUnit's
 * TestRunner\ExecutionFinished subscriber is a single point of failure —
 * some CI setups or PHPUnit wrapper scripts can swallow that exit code.
 * Run this as a second step in CI, after phpunit:
 *
 *   vendor/bin/phpunit
 *   vendor/bin/explain-lint explain-lint:check
 */
final class CheckCommand extends Command
{
    protected static $defaultName = 'explain-lint:check';

    protected function configure(): void
    {
        $this
            ->setName('explain-lint:check')
            ->setDescription('Fails if the last JUnit report written by explain-lint contains error-severity violations.')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Path to the JUnit XML report.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to explain-lint.php.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = ConfigLoader::load(ConfigLoader::resolvePath($input->getOption('config'), getcwd() ?: '.'));

        $reportPath = $input->getOption('report') ?? $config->report['junit'];
        if (!is_string($reportPath) || $reportPath === '') {
            $output->writeln('<comment>explain-lint:check: no JUnit report configured (report.junit), nothing to check.</comment>');

            return Command::SUCCESS;
        }

        if (!is_file($reportPath)) {
            $output->writeln(sprintf('<error>explain-lint:check: report file not found: %s</error>', $reportPath));

            return Command::FAILURE;
        }

        if ($config->mode !== 'strict') {
            $output->writeln('<comment>explain-lint:check: mode is "warn", skipping enforcement.</comment>');

            return Command::SUCCESS;
        }

        $document = new \DOMDocument();
        $document->load($reportPath);

        $errorCount = 0;
        foreach ($document->getElementsByTagName('failure') as $failure) {
            if ($failure->getAttribute('severity') === 'error') {
                $errorCount++;
                $output->writeln(sprintf('<error>%s</error>', $failure->getAttribute('message')));
            }
        }

        if ($errorCount > 0) {
            $output->writeln(sprintf('<error>explain-lint:check: %d error-severity violation(s) found.</error>', $errorCount));

            return Command::FAILURE;
        }

        $output->writeln('<info>explain-lint:check: no error-severity violations.</info>');

        return Command::SUCCESS;
    }
}
