<?php

declare(strict_types=1);

namespace ExplainLint\Config;

use ExplainLint\ReasonCode;
use ExplainLint\Severity;

final class Config
{
    /**
     * @param array<string, ConnectionConfig> $connections
     * @param array<string, string> $allowlistTables Table name => reason.
     * @param array<string, string> $allowlistFingerprints Fingerprint => reason.
     * @param list<string> $ignoreQueries Regex patterns.
     * @param list<string> $ignorePaths Glob patterns, relative to project root.
     * @param array{console: bool, junit: ?string, github_annotations: string} $report
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $connections,
        public readonly array $allowlistTables,
        public readonly array $allowlistFingerprints,
        public readonly array $ignoreQueries,
        public readonly array $ignorePaths,
        public readonly array $report,
        public readonly string $projectRoot,
    ) {
    }

    public static function default(): self
    {
        return self::fromArray([]);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw, string $projectRoot = ''): self
    {
        $connections = [];
        foreach (($raw['connections'] ?? ['default' => []]) as $name => $connectionRaw) {
            $connections[$name] = ConnectionConfig::fromArray(is_array($connectionRaw) ? $connectionRaw : []);
        }

        if (!isset($connections['default'])) {
            $connections['default'] = ConnectionConfig::fromArray([]);
        }

        return new self(
            mode: (string) ($raw['mode'] ?? 'warn'),
            connections: $connections,
            allowlistTables: array_map('strval', $raw['allowlist'] ?? []),
            allowlistFingerprints: array_map('strval', $raw['allowlist_fingerprints'] ?? []),
            ignoreQueries: array_map('strval', $raw['ignore_queries'] ?? []),
            ignorePaths: array_map('strval', $raw['ignore_paths'] ?? []),
            report: [
                'console' => (bool) ($raw['report']['console'] ?? true),
                'junit' => $raw['report']['junit'] ?? null,
                'github_annotations' => (string) ($raw['report']['github_annotations'] ?? 'auto'),
            ],
            projectRoot: $projectRoot,
        );
    }

    public function connection(string $name): ConnectionConfig
    {
        return $this->connections[$name] ?? $this->connections['default'];
    }

    /**
     * Resolves the effective severity for a rule on a connection, or null if
     * the rule is disabled ('off') for that connection.
     *
     * index_rejected defaults to Info regardless of the global mode unless a
     * connection explicitly overrides it — the optimizer choosing to ignore
     * an available index is frequently the *correct* call for low-selectivity
     * predicates, so it should never silently start failing builds just
     * because the project flips its global mode to 'strict'.
     */
    public function severityFor(string $connectionName, ReasonCode $reasonCode): ?Severity
    {
        $rule = $this->connection($connectionName)->rules[$reasonCode->value] ?? null;

        if ($rule !== null) {
            return match ($rule) {
                'off' => null,
                'strict' => Severity::Error,
                default => Severity::Warning,
            };
        }

        if ($reasonCode === ReasonCode::IndexRejected) {
            return Severity::Info;
        }

        return $this->mode === 'strict' ? Severity::Error : Severity::Warning;
    }

    public function allowlistReasonForTable(string $table): ?string
    {
        return $this->allowlistTables[$table] ?? null;
    }

    public function allowlistReasonForFingerprint(string $fingerprint): ?string
    {
        return $this->allowlistFingerprints[$fingerprint] ?? null;
    }

    public function isIgnoredQuery(string $sql): bool
    {
        foreach ($this->ignoreQueries as $pattern) {
            if (@preg_match($pattern, $sql) === 1) {
                return true;
            }
        }

        return false;
    }

    public function isIgnoredPath(?string $absoluteFile): bool
    {
        if ($absoluteFile === null || $this->ignorePaths === [] || $this->projectRoot === '') {
            return false;
        }

        $relative = ltrim(str_replace($this->projectRoot, '', $absoluteFile), '/\\');

        foreach ($this->ignorePaths as $pattern) {
            if (fnmatch($pattern, $relative)) {
                return true;
            }
        }

        return false;
    }
}
