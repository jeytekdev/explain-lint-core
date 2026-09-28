<?php

declare(strict_types=1);

namespace ExplainLint\Engine;

use ExplainLint\Adapter\ExplainAdapter;

/**
 * Result of running EXPLAIN for a captured query: either an adapter was
 * found for the driver and the raw plan was captured, or the driver is
 * unsupported (e.g. sqlsrv) and analysis is skipped entirely.
 */
final class ExplainOutcome
{
    /**
     * @param array<string, mixed> $plan
     */
    private function __construct(
        public readonly bool $supported,
        public readonly string $driver,
        public readonly string $renderedSql,
        public readonly ?ExplainAdapter $adapter,
        public readonly array $plan,
    ) {
    }

    public static function unsupported(string $driver, string $renderedSql): self
    {
        return new self(false, $driver, $renderedSql, null, []);
    }

    /**
     * @param array<string, mixed> $plan
     */
    public static function analyzed(string $driver, string $renderedSql, ExplainAdapter $adapter, array $plan): self
    {
        return new self(true, $driver, $renderedSql, $adapter, $plan);
    }
}
