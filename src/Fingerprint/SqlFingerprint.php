<?php

declare(strict_types=1);

namespace ExplainLint\Fingerprint;

/**
 * Normalizes SQL into a canonical shape and hashes it, so that
 * `WHERE id = 1` and `WHERE id = 2` collapse to the same fingerprint.
 *
 * This is intentionally a dumb lexical normalizer, not a full SQL parser:
 * table/column names for a violation are read back out of the EXPLAIN plan
 * itself (see MySqlAdapter/PostgresAdapter), so the fingerprinter never
 * needs to understand SQL grammar beyond "find literals and IN-lists".
 */
final class SqlFingerprint
{
    public static function normalize(string $sql): string
    {
        $sql = trim($sql);
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        // Collapse arbitrary-length IN (?, ?, ?) / IN (1, 2, 3) lists to one marker
        // before literal substitution, so lists of different lengths still fingerprint
        // the same way.
        $sql = preg_replace('/\bIN\s*\(\s*(?:\?|\$\d+|[^()]+?)(?:\s*,\s*(?:\?|\$\d+|[^()]+?))*\s*\)/i', 'IN (...)', $sql) ?? $sql;

        $sql = self::maskLiterals($sql);

        return strtolower($sql);
    }

    public static function hash(string $sql): string
    {
        return sha1(self::normalize($sql));
    }

    /**
     * Replaces string, numeric and named/positional placeholder literals
     * with a single `?` marker, respecting quoted strings so operators or
     * keywords inside a literal are never mistaken for SQL syntax.
     *
     * Only `'...'` is a string literal in SQL. `"..."` and `` `...` `` are
     * *identifier* quoting (ANSI SQL/PostgreSQL and MySQL respectively,
     * e.g. `"forum"."sections"` or `` `forum`.`sections` ``) — those must be
     * passed through untouched, not masked, or every distinctly-named
     * table/column collapses onto the same fingerprint.
     */
    private static function maskLiterals(string $sql): string
    {
        $length = strlen($sql);
        $out = '';
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === "'") {
                $j = self::findClosingQuote($sql, $i, $char);
                $out .= '?';
                $i = $j + 1;
                continue;
            }

            if ($char === '"' || $char === '`') {
                $j = self::findClosingQuote($sql, $i, $char);
                $out .= substr($sql, $i, $j - $i + 1);
                $i = $j + 1;
                continue;
            }

            // Named (:name) or positional ($1) placeholders collapse to the same marker.
            if ($char === ':' && $i + 1 < $length && ctype_alpha($sql[$i + 1])) {
                $j = $i + 1;
                while ($j < $length && (ctype_alnum($sql[$j]) || $sql[$j] === '_')) {
                    $j++;
                }
                $out .= '?';
                $i = $j;
                continue;
            }

            if ($char === '$' && $i + 1 < $length && ctype_digit($sql[$i + 1])) {
                $j = $i + 1;
                while ($j < $length && ctype_digit($sql[$j])) {
                    $j++;
                }
                $out .= '?';
                $i = $j;
                continue;
            }

            // Bare numeric literal, but not a table/column name like `col2`.
            if (ctype_digit($char) && ($i === 0 || !self::isIdentifierChar($sql[$i - 1]))) {
                $j = $i;
                while ($j < $length && (ctype_digit($sql[$j]) || $sql[$j] === '.')) {
                    $j++;
                }
                $out .= '?';
                $i = $j;
                continue;
            }

            $out .= $char;
            $i++;
        }

        return $out;
    }

    private static function isIdentifierChar(string $char): bool
    {
        return ctype_alnum($char) || $char === '_';
    }

    /**
     * Returns the index of the closing quote matching the quote character
     * at $start, handling backslash escapes and SQL-style doubled-quote
     * escapes (`''` inside a `'...'` literal, `""`/`` `` `` inside a quoted
     * identifier). Falls back to end-of-string if unterminated.
     */
    private static function findClosingQuote(string $sql, int $start, string $quote): int
    {
        $length = strlen($sql);
        $j = $start + 1;

        while ($j < $length) {
            if ($sql[$j] === '\\' && $j + 1 < $length) {
                $j += 2;
                continue;
            }
            if ($sql[$j] === $quote) {
                if ($j + 1 < $length && $sql[$j + 1] === $quote) {
                    $j += 2;
                    continue;
                }

                return $j;
            }
            $j++;
        }

        return $length - 1;
    }
}
