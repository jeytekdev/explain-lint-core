<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Config;

final class ConfigLoader
{
    public static function load(string $path): Config
    {
        if (!is_file($path)) {
            return Config::default();
        }

        $raw = require $path;

        if (!is_array($raw)) {
            throw new \RuntimeException(sprintf('Config file "%s" must return an array.', $path));
        }

        return Config::fromArray($raw, dirname(realpath($path) ?: $path));
    }

    /**
     * Resolves a config path relative to the working directory, defaulting
     * to `explain-lint.php` in the project root.
     */
    public static function resolvePath(?string $configured, string $workingDirectory): string
    {
        $path = $configured ?? 'explain-lint.php';

        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return rtrim($workingDirectory, '/\\') . DIRECTORY_SEPARATOR . $path;
    }
}
