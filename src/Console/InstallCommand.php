<?php

declare(strict_types=1);

namespace ExplainLint\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class InstallCommand extends Command
{
    protected static $defaultName = 'explain-lint:install';

    protected function configure(): void
    {
        $this
            ->setName('explain-lint:install')
            ->setDescription('Registers the ExplainLintExtension in phpunit.xml and creates explain-lint.php.')
            ->addOption('phpunit-config', null, InputOption::VALUE_REQUIRED, 'Path to phpunit.xml', 'phpunit.xml')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to create explain-lint.php at', 'explain-lint.php');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = getcwd() ?: '.';
        $phpunitConfigPath = $this->resolve($cwd, (string) $input->getOption('phpunit-config'));
        $configPath = $this->resolve($cwd, (string) $input->getOption('config'));

        $this->installPhpunitExtension($phpunitConfigPath, $output);
        $this->createConfigFile($configPath, $output);

        return Command::SUCCESS;
    }

    private function installPhpunitExtension(string $phpunitConfigPath, OutputInterface $output): void
    {
        if (!is_file($phpunitConfigPath)) {
            $alternate = preg_replace('/\.xml$/', '.xml.dist', $phpunitConfigPath);
            if ($alternate !== null && is_file($alternate)) {
                $phpunitConfigPath = $alternate;
            } else {
                $output->writeln(sprintf('<error>Could not find %s — create it first (e.g. `phpunit --generate-configuration`).</error>', $phpunitConfigPath));

                return;
            }
        }

        $document = new \DOMDocument();
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;
        $document->load($phpunitConfigPath);

        $root = $document->documentElement;
        if ($root === null) {
            $output->writeln('<error>phpunit.xml has no root element.</error>');

            return;
        }

        $existing = $root->getElementsByTagName('extensions')->item(0);
        if ($existing !== null) {
            foreach ($existing->getElementsByTagName('bootstrap') as $bootstrap) {
                if ($bootstrap->getAttribute('class') === 'ExplainLint\PHPUnit\ExplainLintExtension') {
                    $output->writeln('<comment>ExplainLintExtension is already registered in ' . $phpunitConfigPath . '.</comment>');

                    return;
                }
            }
            $extensions = $existing;
        } else {
            $extensions = $document->createElement('extensions');
            $root->appendChild($extensions);
        }

        $bootstrap = $document->createElement('bootstrap');
        $bootstrap->setAttribute('class', 'ExplainLint\PHPUnit\ExplainLintExtension');
        $parameter = $document->createElement('parameter');
        $parameter->setAttribute('name', 'config');
        $parameter->setAttribute('value', 'explain-lint.php');
        $bootstrap->appendChild($parameter);
        $extensions->appendChild($bootstrap);

        $document->save($phpunitConfigPath);
        $output->writeln('<info>Registered ExplainLintExtension in ' . $phpunitConfigPath . '.</info>');
    }

    private function createConfigFile(string $configPath, OutputInterface $output): void
    {
        if (is_file($configPath)) {
            $output->writeln('<comment>' . $configPath . ' already exists, leaving it untouched.</comment>');

            return;
        }

        $stub = __DIR__ . '/../../stubs/explain-lint.php.stub';
        copy($stub, $configPath);
        $output->writeln('<info>Created ' . $configPath . '.</info>');
    }

    private function resolve(string $cwd, string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . $path;
    }
}
