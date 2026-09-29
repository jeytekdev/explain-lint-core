# jeytekdev/explain-lint

Re-runs `EXPLAIN` against every SQL query captured during your test suite, and fails the build when a query does a full table scan, loses an index, or needs a filesort/temporary table.

## Why

N+1 detectors (`beyondcode/laravel-query-detector` and friends) catch too many *similar* queries in one request — they say nothing about a single query that's structurally bad. A full table scan on a large table, or an index quietly dropped by a migration, executes exactly once per test and sails straight through an N+1 check. It only shows up later, as a production slowdown.

explain-lint complements N+1 detectors, it doesn't replace them: they catch *volume*, this catches *structural regressions in a single query*.

## How it works

1. A capture adapter (framework-agnostic PDO wrapper, Laravel `DB::listen()` bridge, or Doctrine DBAL middleware) records every SQL statement executed during a test, on the same connection it ran on.
2. After the test finishes, explain-lint re-runs a plain `EXPLAIN` for each distinct query **on that same connection/session** — this is what lets it see temp tables, uncommitted data inside a test transaction, and session-level optimizer settings. EXPLAIN itself never executes the query, so this is safe even for INSERT/UPDATE/DELETE.
3. The EXPLAIN plan is parsed into structural findings: full table scan, no index available, optimizer rejected an available index, filesort, temporary table, high row estimate.
4. A rule engine filters out likely false positives (tiny tables, small tables without a selective predicate, allowlisted tables/queries) and assigns a severity.
5. In `strict` mode, any Error-severity violation fails the build.

## Install

```bash
composer require --dev jeytekdev/explain-lint
```

Pick the guide for your stack:

- [Laravel](../laravel/README.md) — `jeytekdev/explain-lint-laravel`
- [Symfony / Doctrine DBAL](../doctrine/README.md) — `jeytekdev/explain-lint-doctrine`
- [Yii2](../yii2/README.md) — `jeytekdev/explain-lint-yii2`
- Bare PDO (below)

### Bare PDO, 2 minutes

```php
use ExplainLint\Pdo\ExplainLintPdo;

$pdo = new ExplainLintPdo('mysql:host=127.0.0.1;dbname=app_test', 'root', '', null, connectionName: 'default');
```

Use `$pdo` exactly like a normal `PDO` instance (`instanceof \PDO` will be `false` — this is a composition wrapper, not a subclass; see the class docblock for why). Then register the extension:

```xml
<!-- phpunit.xml -->
<extensions>
    <bootstrap class="ExplainLint\PHPUnit\ExplainLintExtension">
        <parameter name="config" value="explain-lint.php"/>
    </bootstrap>
</extensions>
```

Or let the installer do both steps:

```bash
vendor/bin/explain-lint explain-lint:install
```

This works for [Pest](https://pestphp.com) too, with zero extra glue — Pest runs on the same PHPUnit event bus.

## Configuration

`explain-lint.php` in your project root:

```php
<?php

return [
    'mode' => 'warn', // 'warn' | 'strict'

    'connections' => [
        'default' => [
            'driver' => 'mysql', // 'mysql' | 'pgsql'
            'rules' => [
                'full_table_scan' => 'strict',
                'no_index_available' => 'strict',
                'index_rejected' => 'warn',
                'filesort' => 'warn',
                'temporary_table' => 'warn',
                'high_row_estimate' => 'warn',
            ],
            'row_estimate_threshold' => 1000,
            'table_size_tiers' => ['tiny' => 100, 'small' => 1000],
        ],
    ],

    'allowlist' => [
        'audit_log' => 'Intentional full scan for the nightly export job — JIRA-123',
    ],
    'allowlist_fingerprints' => [],

    'ignore_queries' => ['/^\s*(CREATE|ALTER|DROP)\s/i'],
    'ignore_paths' => ['database/migrations/*', 'database/seeders/*'],

    'report' => [
        'console' => true,
        'junit' => null,
        'github_annotations' => 'auto',
    ],
];
```

Rules and thresholds are per-connection, not global — a reporting replica with different data volumes can have a different `row_estimate_threshold` than your primary connection. Allowlisting always requires a non-empty `reason` string so it doesn't rot silently. Roll strict-mode out incrementally: flip individual rules from `warn` to `strict` one at a time on a legacy codebase, rather than the whole connection at once.

### Why some findings default to a lower severity

`index_rejected` (the optimizer had a usable index and chose a scan anyway) defaults to `Info`, not `Error`, and stays that way even if you flip the connection to strict — you have to opt in explicitly. The optimizer frequently makes the *correct* call here for low-selectivity predicates; treating every rejected index as a bug would make the tool too noisy to keep enabled.

### False-positive filtering (table-size tiering)

- On **tiny** tables (`<= tiny` rows, default 100) scan-related rules never fire — a 5-row status lookup table doing a full scan is not a bug.
- On **small** tables (`<= small` rows, default 1000) scan-related rules only fire if the query has a selective predicate, determined from the EXPLAIN plan itself (MySQL: `Extra` contains `Using where`; PostgreSQL: a `Filter`/`Index Cond` is present) — never by parsing your SQL.
- Above that, scan-related rules always apply.

Table sizes are read from `information_schema.TABLES.TABLE_ROWS` (MySQL) or `pg_class.reltuples`, falling back to `pg_stat_user_tables.n_live_tup`, falling back to an exact `COUNT(*)` (PostgreSQL), and cached for the run.

## CLI

```bash
vendor/bin/explain-lint explain-lint:install                 # wires up phpunit.xml + explain-lint.php
vendor/bin/explain-lint explain-lint:install --config-only   # only creates explain-lint.php — for Codeception projects, see jeytekdev/explain-lint-codeception
vendor/bin/explain-lint explain-lint:check                   # reads the last JUnit report, fails if mode=strict and it has error-severity violations
```

`explain-lint:check` exists as a reliable second CI step — relying solely on the in-process `exit(1)` from PHPUnit's `TestRunner\ExecutionFinished` event is a single point of failure if something in your CI pipeline swallows PHPUnit's exit code.

```yaml
# CI
- run: vendor/bin/phpunit
- run: vendor/bin/explain-lint explain-lint:check
```

## Scope of this release

Implemented: MySQL/MariaDB + PostgreSQL adapters, PHPUnit 10/11 (and Pest) integration, console/JUnit/GitHub Actions reporting, Laravel/Doctrine/Yii2 bridges.

Not implemented yet (see [CONTRIBUTING.md](../../CONTRIBUTING.md) — good first issues):

- SQLite — currently a no-op adapter that always passes.
- `EXPLAIN ANALYZE` mode with real row counts (`ExplainMode` has the extension point).
- Historical analytics across runs, HTML report.

## License

MIT
