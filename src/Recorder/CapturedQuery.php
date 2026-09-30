<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Recorder;

/**
 * An SQL statement observed by one of the capture adapters (ExplainLintPdo,
 * the Laravel DB::listen() bridge, or the Doctrine DBAL middleware), together
 * with enough context to re-run EXPLAIN on the exact same connection/session.
 */
final class CapturedQuery
{
    /**
     * @param array<int|string, mixed> $params Positional (0-indexed list) or named bindings.
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $params,
        public readonly \PDO $connection,
        public readonly string $connectionName,
        public readonly QueryPhase $phase = QueryPhase::Unspecified,
        public readonly ?string $callerFile = null,
    ) {
    }
}
