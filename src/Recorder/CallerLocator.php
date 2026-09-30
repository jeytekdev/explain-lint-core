<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Recorder;

/**
 * Best-effort lookup of "which file triggered this query", used by
 * RuleEngine's `ignore_paths` filter (e.g. migrations/seeders). Only
 * meaningful for capture points that sit directly in the call stack of
 * application code — ExplainLintPdo does. The Laravel/Doctrine bridges
 * receive their queries via a framework-level event/callback, so the
 * caller frame usually resolves to framework internals rather than the
 * migration file itself; ignore_paths is most reliable combined with the
 * default `phase = Setup` exclusion for those bridges.
 */
final class CallerLocator
{
    public static function locate(): ?string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null) {
                continue;
            }

            if (str_contains($file, '/explain-lint/') || str_contains($file, '\\Jeytekdev\\ExplainLint\\')) {
                continue;
            }

            return $file;
        }

        return null;
    }
}
