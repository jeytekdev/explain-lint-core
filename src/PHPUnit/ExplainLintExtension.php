<?php

declare(strict_types=1);

namespace ExplainLint\PHPUnit;

use ExplainLint\Adapter\MySqlAdapter;
use ExplainLint\Adapter\PostgresAdapter;
use ExplainLint\Adapter\SqliteNoopAdapter;
use ExplainLint\Config\Config;
use ExplainLint\Config\ConfigLoader;
use ExplainLint\Engine\ExplainRunner;
use ExplainLint\Recorder\QueryLedger;
use ExplainLint\Recorder\QueryRecorder;
use ExplainLint\Report\ResultCollector;
use ExplainLint\Rules\RuleEngine;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Register in phpunit.xml:
 *
 *   <extensions>
 *       <bootstrap class="ExplainLint\PHPUnit\ExplainLintExtension">
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
