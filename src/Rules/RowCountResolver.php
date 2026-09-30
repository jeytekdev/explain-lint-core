<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Rules;

interface RowCountResolver
{
    public function rowCountFor(\PDO $connection, string $driver, string $connectionName, string $table): int;
}
