<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Pdo;

use Jeytekdev\ExplainLint\Recorder\CallerLocator;
use Jeytekdev\ExplainLint\Recorder\CapturedQuery;
use Jeytekdev\ExplainLint\Recorder\QueryPhase;
use Jeytekdev\ExplainLint\Recorder\QueryRecorder;

/**
 * Drop-in PDO wrapper for framework-agnostic usage (plain PDO apps, or any
 * integration not already covered by the Laravel/Doctrine bridges).
 *
 * This deliberately wraps a real \PDO instance by composition instead of
 * extending \PDO. Extending \PDO and overriding prepare() to return a
 * decorator would violate PHP's covariant-return-type rules — \PDO::prepare()
 * is declared `: PDOStatement|false`, and a decorator that isn't itself a
 * PDOStatement subtype cannot legally be returned from an override of that
 * signature (PHP raises a fatal "declaration must be compatible" error at
 * class-definition time). Composition sidesteps that entirely: this class
 * simply exposes the same method names as \PDO, backed by a real \PDO
 * instance underneath. `instanceof \PDO` will therefore be false for this
 * wrapper — code that strictly requires a `\PDO` type-hint should use the
 * real connection instead (available via `$captured->connection` in
 * consumers of QueryRecorder, or by not routing that particular call
 * through this wrapper).
 *
 * Also usable to wrap an *already-connected* \PDO instance (pass it as
 * `$dsn` instead of a DSN string) — needed by bridges such as Yii2 that
 * hand you a live connection rather than owning the DSN themselves. This
 * still records on the exact same connection/session, since no second
 * \PDO is created in that case.
 *
 * @method string quote(string $string, int $type = \PDO::PARAM_STR)
 * @method bool beginTransaction()
 * @method bool commit()
 * @method bool rollBack()
 * @method bool inTransaction()
 * @method string lastInsertId(?string $name = null)
 * @method mixed getAttribute(int $attribute)
 * @method bool setAttribute(int $attribute, mixed $value)
 * @method array errorInfo()
 * @method string errorCode()
 */
class ExplainLintPdo
{
    private readonly \PDO $pdo;
    private readonly QueryRecorder $recorder;

    public function __construct(
        \PDO|string $dsn,
        ?string $username = null,
        ?string $password = null,
        ?array $options = null,
        private readonly string $connectionName = 'default',
    ) {
        $this->pdo = $dsn instanceof \PDO ? $dsn : new \PDO($dsn, $username, $password, $options);
        $this->recorder = QueryRecorder::instance();
    }

    /**
     * Convenience proxy to the shared recorder's phase (see QueryRecorder).
     * Only needed for manual/advanced setups; the PHPUnit extension manages
     * this automatically.
     */
    public function setPhase(QueryPhase $phase): void
    {
        $this->recorder->setPhase($phase);
    }

    /**
     * The real underlying \PDO connection — pass this wherever a strict
     * `\PDO` type-hint is required, or use it to run EXPLAIN yourself.
     */
    public function connection(): \PDO
    {
        return $this->pdo;
    }

    public function connectionName(): string
    {
        return $this->connectionName;
    }

    public function prepare(string $query, array $options = []): ExplainLintPdoStatement|false
    {
        $statement = $this->pdo->prepare($query, $options);
        if ($statement === false) {
            return false;
        }

        return new ExplainLintPdoStatement(
            $statement,
            $query,
            $this->pdo,
            $this->recorder,
            $this->connectionName,
            CallerLocator::locate(),
        );
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $statement = $fetchMode === null
            ? $this->pdo->query($query)
            : $this->pdo->query($query, $fetchMode, ...$fetchModeArgs);

        if ($statement !== false) {
            $this->recorder->record(new CapturedQuery(
                $query,
                [],
                $this->pdo,
                $this->connectionName,
                $this->recorder->currentPhase(),
                CallerLocator::locate(),
            ));
        }

        return $statement;
    }

    public function exec(string $statement): int|false
    {
        $result = $this->pdo->exec($statement);

        if ($result !== false) {
            $this->recorder->record(new CapturedQuery(
                $statement,
                [],
                $this->pdo,
                $this->connectionName,
                $this->recorder->currentPhase(),
                CallerLocator::locate(),
            ));
        }

        return $result;
    }

    /**
     * Delegates every other PDO method (quote, transactions, attributes, ...) untouched.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->pdo->{$name}(...$arguments);
    }
}
