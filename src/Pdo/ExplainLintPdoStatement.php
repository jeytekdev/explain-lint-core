<?php

declare(strict_types=1);

namespace ExplainLint\Pdo;

use ExplainLint\Recorder\CapturedQuery;
use ExplainLint\Recorder\QueryRecorder;

/**
 * Decorator around a real \PDOStatement. Composition instead of inheritance
 * on purpose: \PDOStatement's constructor is not something user code can
 * call directly (statements are only ever produced internally by the PDO
 * driver during prepare()), so a subclass cannot "wrap" an already-prepared
 * statement — the only supported way to intercept execute() while staying a
 * true PDOStatement is PDO::ATTR_STATEMENT_CLASS, which is unnecessary
 * complexity here since nothing requires this object to pass `instanceof
 * \PDOStatement`. Every method not explicitly overridden below is
 * transparently forwarded via __call.
 *
 * @method bool closeCursor()
 * @method int columnCount()
 * @method mixed fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0)
 * @method array fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args)
 * @method mixed fetchColumn(int $column = 0)
 * @method object|false fetchObject(?string $class = 'stdClass', array $constructorArgs = [])
 * @method bool setFetchMode(int $mode, mixed ...$args)
 * @method int rowCount()
 * @method string errorCode()
 * @method array errorInfo()
 * @method mixed getAttribute(int $attribute)
 * @method bool setAttribute(int $attribute, mixed $value)
 */
final class ExplainLintPdoStatement
{
    /** @var array<int|string, array{value: mixed, type: int}> */
    private array $boundParams = [];

    public function __construct(
        private readonly \PDOStatement $statement,
        private readonly string $sql,
        private readonly \PDO $connection,
        private readonly QueryRecorder $recorder,
        private readonly string $connectionName,
        private readonly ?string $callerFile,
    ) {
    }

    public function bindValue(int|string $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        $this->boundParams[$param] = ['value' => $value, 'type' => $type];

        return $this->statement->bindValue($param, $value, $type);
    }

    public function bindParam(
        int|string $param,
        mixed &$var,
        int $type = \PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null
    ): bool {
        // Captured by value at bind time. bindParam's by-reference semantics
        // (the caller may mutate $var again before execute()) are not
        // reconstructed exactly, but this is sufficient for reconstructing a
        // representative literal query for EXPLAIN — it does not need to
        // match production data, only the query's structural shape.
        $this->boundParams[$param] = ['value' => $var, 'type' => $type];

        return $this->statement->bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    public function execute(?array $params = null): bool
    {
        $result = $this->statement->execute($params);

        if ($result) {
            $boundParams = $params !== null
                ? $this->normalizeExecuteParams($params)
                : $this->boundParams;

            $this->recorder->record(new CapturedQuery(
                $this->sql,
                $boundParams,
                $this->connection,
                $this->connectionName,
                $this->recorder->currentPhase(),
                $this->callerFile,
            ));
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int|string, array{value: mixed, type: int}>
     */
    private function normalizeExecuteParams(array $params): array
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            $normalized[$key] = ['value' => $value, 'type' => is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR];
        }

        return $normalized;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->statement->{$name}(...$arguments);
    }
}
