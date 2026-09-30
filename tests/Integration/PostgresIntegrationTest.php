<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Tests\Integration;

use Jeytekdev\ExplainLint\Adapter\PostgresAdapter;
use Jeytekdev\ExplainLint\Config\Config;
use Jeytekdev\ExplainLint\Engine\ExplainRunner;
use Jeytekdev\ExplainLint\Fingerprint\SqlFingerprint;
use Jeytekdev\ExplainLint\Recorder\CapturedQuery;
use Jeytekdev\ExplainLint\Recorder\QueryPhase;
use Jeytekdev\ExplainLint\Rules\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real PostgreSQL instance. See MySqlIntegrationTest for the
 * skip-if-unavailable rationale.
 */
final class PostgresIntegrationTest extends TestCase
{
    private \PDO $pdo;
    private ExplainRunner $runner;
    private RuleEngine $ruleEngine;

    protected function setUp(): void
    {
        $dsn = getenv('EXPLAIN_LINT_TEST_PGSQL_DSN');
        if ($dsn === false) {
            self::markTestSkipped('EXPLAIN_LINT_TEST_PGSQL_DSN not set; see docker-compose.yml.');
        }

        $this->pdo = new \PDO(
            $dsn,
            getenv('EXPLAIN_LINT_TEST_PGSQL_USER') ?: 'postgres',
            getenv('EXPLAIN_LINT_TEST_PGSQL_PASSWORD') ?: 'postgres',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $this->pdo->exec('DROP TABLE IF EXISTS el_tiny, el_small, el_large');
        $this->pdo->exec('CREATE TABLE el_tiny (id SERIAL PRIMARY KEY, status VARCHAR(20))');
        $this->pdo->exec('CREATE TABLE el_small (id SERIAL PRIMARY KEY, status VARCHAR(20))');
        $this->pdo->exec('CREATE TABLE el_large (id SERIAL PRIMARY KEY, status VARCHAR(20))');
        $this->pdo->exec('CREATE INDEX el_large_status_idx ON el_large (status)');

        $this->seed('el_tiny', 5);
        $this->seed('el_small', 500);
        $this->seed('el_large', 5000);
        // A single rare value, so a query against it is genuinely selective —
        // status is otherwise an even 50/50 split, which Postgres's planner
        // correctly prefers to sequentially scan rather than use the index.
        $this->pdo->exec("INSERT INTO el_large (status) VALUES ('rare-value')");
        $this->pdo->exec('ANALYZE el_tiny, el_small, el_large');

        $this->runner = new ExplainRunner(adapters: [new PostgresAdapter()]);
        $this->ruleEngine = new RuleEngine(Config::fromArray([
            'mode' => 'strict',
            'connections' => ['default' => ['driver' => 'pgsql']],
        ]));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS el_tiny, el_small, el_large, el_no_index');
        }
    }

    private function seed(string $table, int $count): void
    {
        $statement = $this->pdo->prepare("INSERT INTO {$table} (status) VALUES (:status)");
        for ($i = 0; $i < $count; $i++) {
            $statement->execute(['status' => $i % 2 === 0 ? 'active' : 'inactive']);
        }
    }

    public function testSeqScanOnTinyTableDoesNotFail(): void
    {
        $verdict = $this->evaluate("SELECT * FROM el_tiny WHERE status = 'active'");

        self::assertTrue($verdict->passed, 'tiny tables must never fail scan rules');
    }

    public function testIndexScanOnLargeTableStaysGreen(): void
    {
        $verdict = $this->evaluate("SELECT * FROM el_large WHERE status = 'rare-value'");

        self::assertTrue($verdict->passed);
    }

    public function testSeqScanOnLargeTableWithoutIndexFails(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS el_no_index');
        $this->pdo->exec('CREATE TABLE el_no_index (id SERIAL PRIMARY KEY, status VARCHAR(20))');
        $this->seed('el_no_index', 5000);
        $this->pdo->exec('ANALYZE el_no_index');

        $verdict = $this->evaluate("SELECT * FROM el_no_index WHERE status = 'active'");

        self::assertFalse($verdict->passed);

        $this->pdo->exec('DROP TABLE el_no_index');
    }

    private function evaluate(string $sql): \Jeytekdev\ExplainLint\Verdict
    {
        $query = new CapturedQuery($sql, [], $this->pdo, 'default', QueryPhase::Test);
        $outcome = $this->runner->run($query);
        $fingerprint = SqlFingerprint::hash($sql);

        return $this->ruleEngine->evaluate($query, $outcome, $fingerprint);
    }
}
