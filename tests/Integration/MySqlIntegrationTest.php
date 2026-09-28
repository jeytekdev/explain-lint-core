<?php

declare(strict_types=1);

namespace ExplainLint\Tests\Integration;

use ExplainLint\Adapter\MySqlAdapter;
use ExplainLint\Config\Config;
use ExplainLint\Engine\ExplainRunner;
use ExplainLint\Fingerprint\SqlFingerprint;
use ExplainLint\Recorder\CapturedQuery;
use ExplainLint\Recorder\QueryPhase;
use ExplainLint\Rules\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real MySQL/MariaDB instance. Requires docker-compose (see
 * repo root) or CI service containers; skips itself if the DSN env var
 * isn't set so `--testsuite unit` never needs a database.
 */
final class MySqlIntegrationTest extends TestCase
{
    private \PDO $pdo;
    private ExplainRunner $runner;
    private RuleEngine $ruleEngine;

    protected function setUp(): void
    {
        $dsn = getenv('EXPLAIN_LINT_TEST_MYSQL_DSN');
        if ($dsn === false) {
            self::markTestSkipped('EXPLAIN_LINT_TEST_MYSQL_DSN not set; see docker-compose.yml.');
        }

        $this->pdo = new \PDO(
            $dsn,
            getenv('EXPLAIN_LINT_TEST_MYSQL_USER') ?: 'root',
            getenv('EXPLAIN_LINT_TEST_MYSQL_PASSWORD') ?: 'root',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $this->pdo->exec('DROP TABLE IF EXISTS el_tiny, el_small, el_large');
        $this->pdo->exec('CREATE TABLE el_tiny (id INT PRIMARY KEY AUTO_INCREMENT, status VARCHAR(20))');
        $this->pdo->exec('CREATE TABLE el_small (id INT PRIMARY KEY AUTO_INCREMENT, status VARCHAR(20))');
        $this->pdo->exec('CREATE TABLE el_large (id INT PRIMARY KEY AUTO_INCREMENT, status VARCHAR(20), INDEX(status))');

        for ($i = 0; $i < 5; $i++) {
            $this->pdo->exec("INSERT INTO el_tiny (status) VALUES ('active')");
        }
        for ($i = 0; $i < 500; $i++) {
            $this->pdo->exec("INSERT INTO el_small (status) VALUES ('active')");
        }
        for ($i = 0; $i < 5000; $i++) {
            $this->pdo->exec("INSERT INTO el_large (status) VALUES ('" . ($i % 2 === 0 ? 'active' : 'inactive') . "')");
        }
        // ANALYZE TABLE returns a result set — PDO::exec() is only for
        // statements that don't, and leaves the connection with a dangling
        // unbuffered result that fails the next query() call.
        $this->pdo->query('ANALYZE TABLE el_tiny, el_small, el_large')->closeCursor();

        $this->runner = new ExplainRunner(adapters: [new MySqlAdapter()]);
        $this->ruleEngine = new RuleEngine(Config::fromArray([
            'mode' => 'strict',
            'connections' => ['default' => ['driver' => 'mysql']],
        ]));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS el_tiny, el_small, el_large');
        }
    }

    public function testFullScanOnTinyTableDoesNotFail(): void
    {
        $verdict = $this->evaluate("SELECT * FROM el_tiny WHERE status = 'active'");

        self::assertTrue($verdict->passed, 'tiny tables must never fail scan rules');
    }

    public function testFullScanOnLargeTableWithSelectivePredicateFails(): void
    {
        $verdict = $this->evaluate("SELECT * FROM el_large WHERE status = 'nonexistent-value-forcing-full-scan-consideration'");

        // el_large has an index on status, so the optimizer should use it —
        // this asserts the *good* path stays green.
        self::assertTrue($verdict->passed);
    }

    public function testFullScanOnLargeTableWithoutIndexFails(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS el_no_index');
        $this->pdo->exec('CREATE TABLE el_no_index (id INT PRIMARY KEY AUTO_INCREMENT, status VARCHAR(20))');
        for ($i = 0; $i < 5000; $i++) {
            $this->pdo->exec("INSERT INTO el_no_index (status) VALUES ('" . ($i % 2 === 0 ? 'active' : 'inactive') . "')");
        }
        $this->pdo->query('ANALYZE TABLE el_no_index')->closeCursor();

        $verdict = $this->evaluate("SELECT * FROM el_no_index WHERE status = 'active'");

        self::assertFalse($verdict->passed);

        $this->pdo->exec('DROP TABLE el_no_index');
    }

    private function evaluate(string $sql): \ExplainLint\Verdict
    {
        $query = new CapturedQuery($sql, [], $this->pdo, 'default', QueryPhase::Test);
        $outcome = $this->runner->run($query);
        $fingerprint = SqlFingerprint::hash($sql);

        return $this->ruleEngine->evaluate($query, $outcome, $fingerprint);
    }
}
