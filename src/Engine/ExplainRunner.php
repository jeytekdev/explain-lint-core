<?php

declare(strict_types=1);

namespace ExplainLint\Engine;

use ExplainLint\Adapter\ExplainAdapter;
use ExplainLint\Recorder\CapturedQuery;

/**
 * Runs EXPLAIN for a captured query on the exact same PDO connection/session
 * it was originally executed on — this matters because it's what lets
 * EXPLAIN see temp tables, uncommitted data inside a test transaction, and
 * session-level optimizer variables. A second, fresh connection would not
 * see any of that.
 */
final class ExplainRunner
{
    // SET/BEGIN/COMMIT/SHOW/etc. cannot be EXPLAINed and must never be sent to
    // `EXPLAIN ...` — only DQL/DML statements can.
    private const EXPLAINABLE_PATTERN = '/^\s*(SELECT|INSERT|UPDATE|DELETE|WITH|REPLACE)\b/i';

    /**
     * @param list<ExplainAdapter> $adapters
     */
    public function __construct(
        private readonly BoundQueryRenderer $renderer = new BoundQueryRenderer(),
        private readonly array $adapters = [],
    ) {
    }

    public function run(CapturedQuery $query, ExplainMode $mode = ExplainMode::Plan): ExplainOutcome
    {
        if ($mode !== ExplainMode::Plan) {
            throw new \InvalidArgumentException('Only ExplainMode::Plan is implemented in this release.');
        }

        $driver = (string) $query->connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $rendered = $this->renderer->render($query->sql, $query->params, $query->connection, $driver);

        if (preg_match(self::EXPLAINABLE_PATTERN, $query->sql) !== 1) {
            return ExplainOutcome::unsupported($driver, $rendered);
        }

        $adapter = $this->resolveAdapter($driver);
        if ($adapter === null) {
            return ExplainOutcome::unsupported($driver, $rendered);
        }

        $plan = $adapter->explain($query->connection, $rendered);

        return ExplainOutcome::analyzed($driver, $rendered, $adapter, $plan);
    }

    private function resolveAdapter(string $driver): ?ExplainAdapter
    {
        foreach ($this->adapters as $adapter) {
            if (in_array($driver, $adapter->driverNames(), true)) {
                return $adapter;
            }
        }

        return null;
    }
}
