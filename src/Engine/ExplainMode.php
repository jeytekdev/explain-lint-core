<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Engine;

/**
 * `Plan` runs a bare EXPLAIN — side-effect-free even for UPDATE/DELETE/INSERT,
 * since it only asks the optimizer to build a plan without executing it.
 *
 * `Plan` is the only mode implemented in v1. An `Analyze` case (EXPLAIN
 * ANALYZE with real row counts) is deliberately not added yet — it executes
 * the query for real, which is unsafe to do blindly against write statements
 * captured during a test run, and needs its own opt-in story. This enum is
 * the extension point for that later.
 */
enum ExplainMode
{
    case Plan;
}
