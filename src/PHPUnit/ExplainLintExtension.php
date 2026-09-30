<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\PHPUnit;

use Jeytekdev\ExplainLint\Adapter\MySqlAdapter;
use Jeytekdev\ExplainLint\Adapter\PostgresAdapter;
use Jeytekdev\ExplainLint\Adapter\SqliteNoopAdapter;
use Jeytekdev\ExplainLint\Config\Config;
use Jeytekdev\ExplainLint\Config\ConfigLoader;
use Jeytekdev\ExplainLint\Engine\ExplainRunner;
use Jeytekdev\ExplainLint\Recorder\QueryLedger;
use Jeytekdev\ExplainLint\Recorder\QueryRecorder;
use Jeytekdev\ExplainLint\Report\ResultCollector;
use Jeytekdev\ExplainLint\Rules\RuleEngine;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Register in phpunit.xml:
 *
 *   <extensions>
 *       <bootstrap class="Jeytekdev\ExplainLint\PHPUnit\ExplainLintExtension">
 *           <parameter name="config" value="explain-lint.php"/>
 *       </bootstrap>
 *   </extensions>
 *
 * This works for Pest too — Pest tests run through the same PHPUnit event
 * bus, so no Pest-specific glue is required.
 */
final class ExplainLintExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $configPath = ConfigLoader::resolvePath(
            $parameters->has('config') ? $parameters->get('config') : null,
            getcwd() ?: '.'
        );
        $config = ConfigLoader::load($configPath);

        $recorder = QueryRecorder::instance();
        $ledger = new QueryLedger();
        $explainRunner = new ExplainRunner(adapters: [
            new MySqlAdapter(),
            new PostgresAdapter(),
            new SqliteNoopAdapter(),
        ]);
        $ruleEngine = new RuleEngine($config);
        $results = new ResultCollector();
        $analysisRunner = new TestAnalysisRunner($recorder, $ledger, $explainRunner, $ruleEngine, $results);

        $facade->registerSubscriber(new PreparationStartedSubscriber($recorder));
        $facade->registerSubscriber(new PreparedSubscriber($recorder));
        $facade->registerSubscriber(new FinishedSubscriber($analysisRunner));
        $facade->registerSubscriber(new FailedSubscriber($analysisRunner));
        $facade->registerSubscriber(new ErroredSubscriber($analysisRunner));
        $facade->registerSubscriber(new ExecutionFinishedSubscriber($results, $config));
    }
}
