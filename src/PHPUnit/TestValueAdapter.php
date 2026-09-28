<?php

declare(strict_types=1);

namespace ExplainLint\PHPUnit;

/**
 * PHPUnit's Event\Code\Test value objects went through small renames
 * between 10.0 and 10.5 (and Pest wraps them further). Every access goes
 * through here, defensively, so the extension degrades to a best-effort
 * identifier instead of fatal-erroring if a method isn't present on a given
 * PHPUnit version.
 */
final class TestValueAdapter
{
    public static function id(object $test): string
    {
        if (method_exists($test, 'id')) {
            return (string) $test->id();
        }

        return spl_object_hash($test);
    }

    public static function name(object $test): string
    {
        if (method_exists($test, 'className') && method_exists($test, 'methodName')) {
            return $test->className() . '::' . $test->methodName();
        }

        if (method_exists($test, 'methodNameWithClassName')) {
            return (string) $test->methodNameWithClassName();
        }

        if (method_exists($test, 'name')) {
            return (string) $test->name();
        }

        return self::id($test);
    }

    public static function file(object $test): ?string
    {
        if (method_exists($test, 'file')) {
            $file = $test->file();

            return is_string($file) && $file !== '' ? $file : null;
        }

        return null;
    }
}
