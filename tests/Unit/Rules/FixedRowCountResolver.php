<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Tests\Unit\Rules;

use Jeytekdev\ExplainLint\Rules\RowCountResolver;

final class FixedRowCountResolver implements RowCountResolver
{
    /**
     * @param array<string, int> $rowCounts Table name => row count.
     */
    public function __construct(private readonly array $rowCounts, private readonly int $default = PHP_INT_MAX)
    {
    }

    public function rowCountFor(\PDO $connection, string $driver, string $connectionName, string $table): int
    {
        return $this->rowCounts[$table] ?? $this->default;
    }
}
