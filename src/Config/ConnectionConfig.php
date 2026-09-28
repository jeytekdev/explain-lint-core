<?php

declare(strict_types=1);

namespace ExplainLint\Config;

final class ConnectionConfig
{
    /**
     * @param array<string, string> $rules Rule name (ReasonCode::value) => 'strict'|'warn'|'off'.
     * @param array{tiny: int, small: int} $tableSizeTiers
     */
    public function __construct(
        public readonly string $driver,
        public readonly array $rules,
        public readonly int $rowEstimateThreshold,
        public readonly array $tableSizeTiers,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $tiers = $raw['table_size_tiers'] ?? [];

        return new self(
            driver: (string) ($raw['driver'] ?? 'mysql'),
            rules: array_map('strval', $raw['rules'] ?? []),
            rowEstimateThreshold: (int) ($raw['row_estimate_threshold'] ?? 1000),
            tableSizeTiers: [
                'tiny' => (int) ($tiers['tiny'] ?? 100),
                'small' => (int) ($tiers['small'] ?? 1000),
            ],
        );
    }
}
