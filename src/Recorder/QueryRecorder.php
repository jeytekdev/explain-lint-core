<?php

declare(strict_types=1);

namespace ExplainLint\Recorder;

/**
 * Process-wide buffer that every capture adapter (ExplainLintPdo, the
 * Laravel listener, the Doctrine middleware) writes into, and that the
 * PHPUnit extension drains after each test.
 *
 * A shared singleton is used deliberately: capture adapters live deep
 * inside framework internals (a PDO subclass, a DB::listen() callback, a
 * DBAL driver decorator) where there is no reasonable way to inject a
 * per-test collaborator. Tests run sequentially within one PHP process, so
 * a single static buffer reset between tests is sufficient and keeps every
 * bridge free of DI wiring.
 */
final class QueryRecorder
{
    private static ?self $instance = null;

    /** @var list<CapturedQuery> */
    private array $queries = [];

    // Defaults to Test so framework-agnostic usage without the PHPUnit
    // extension (nothing ever calls setPhase()) analyzes everything by
    // default. The PHPUnit extension narrows this by switching to Setup
    // during setUp() and back to Test once the test method itself starts.
    private QueryPhase $phase = QueryPhase::Test;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @internal for tests only
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function record(CapturedQuery $query): void
    {
        $this->queries[] = $query;
    }

    public function setPhase(QueryPhase $phase): void
    {
        $this->phase = $phase;
    }

    public function currentPhase(): QueryPhase
    {
        return $this->phase;
    }

    /**
     * @return list<CapturedQuery>
     */
    public function all(): array
    {
        return $this->queries;
    }

    /**
     * Returns and clears the buffer.
     *
     * @return list<CapturedQuery>
     */
    public function flush(): array
    {
        $queries = $this->queries;
        $this->queries = [];

        return $queries;
    }

    public function clear(): void
    {
        $this->queries = [];
    }
}
