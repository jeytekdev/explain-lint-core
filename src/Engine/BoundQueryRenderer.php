<?php

declare(strict_types=1);

namespace ExplainLint\Engine;

/**
 * Substitutes bound parameters back into SQL text so EXPLAIN receives a
 * literal query. PDO does not expose a "give me the final SQL" API, so this
 * walks the SQL and replaces `?` / `:name` placeholders outside of string
 * literals with PDO::quote()'d values, respecting the declared PDO param
 * type and MySQL vs. PostgreSQL boolean literal syntax (0/1 vs true/false).
 */
final class BoundQueryRenderer
{
    /**
     * @param array<int|string, mixed> $params Positional (0-indexed list) or named bindings.
     *                                          Values may be raw scalars or ['value' => mixed, 'type' => int] pairs.
     */
    public function render(string $sql, array $params, \PDO $pdo, string $driver): string
    {
        if ($params === []) {
            return $sql;
        }

        $tokens = $this->tokenize($sql);
        $isPositional = array_is_list($params) && array_key_exists(0, $params);
        $positionalIndex = 0;
        $out = '';

        foreach ($tokens as $token) {
            if ($token['type'] !== 'placeholder') {
                $out .= $token['value'];
                continue;
            }

            if ($token['name'] !== null) {
                $key = $token['name'];
                $raw = $params[$key] ?? $params[':' . $key] ?? null;
            } else {
                $key = $isPositional ? $positionalIndex : ($positionalIndex + 1);
                $raw = $params[$key] ?? null;
                $positionalIndex++;
            }

            $out .= $this->quote($raw, $pdo, $driver);
        }

        return $out;
    }

    private function quote(mixed $raw, \PDO $pdo, string $driver): string
    {
        $type = \PDO::PARAM_STR;
        $value = $raw;

        if (is_array($raw) && array_key_exists('value', $raw)) {
            $value = $raw['value'];
            $type = $raw['type'] ?? \PDO::PARAM_STR;
        }

        if ($value === null || $type === \PDO::PARAM_NULL) {
            return 'NULL';
        }

        if ($type === \PDO::PARAM_INT) {
            return (string) (int) $value;
        }

        if (is_bool($value)) {
            return $driver === 'pgsql' ? ($value ? 'true' : 'false') : ($value ? '1' : '0');
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value) ?: '';
        }

        return $pdo->quote((string) $value);
    }

    /**
     * @return list<array{type: 'text'|'placeholder', value: string, name: ?string}>
     */
    private function tokenize(string $sql): array
    {
        $length = strlen($sql);
        $tokens = [];
        $buffer = '';
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $j = $i + 1;
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
                        $j++;
                        break;
                    }
                    $j++;
                }
                $buffer .= substr($sql, $i, $j - $i);
                $i = $j;
                continue;
            }

            if ($char === '?') {
                if ($buffer !== '') {
                    $tokens[] = ['type' => 'text', 'value' => $buffer, 'name' => null];
                    $buffer = '';
                }
                $tokens[] = ['type' => 'placeholder', 'value' => '?', 'name' => null];
                $i++;
                continue;
            }

            if ($char === ':' && $i + 1 < $length && ctype_alpha($sql[$i + 1])) {
                $j = $i + 1;
                while ($j < $length && (ctype_alnum($sql[$j]) || $sql[$j] === '_')) {
                    $j++;
                }
                $name = substr($sql, $i + 1, $j - $i - 1);

                if ($buffer !== '') {
                    $tokens[] = ['type' => 'text', 'value' => $buffer, 'name' => null];
                    $buffer = '';
                }
                $tokens[] = ['type' => 'placeholder', 'value' => ':' . $name, 'name' => $name];
                $i = $j;
                continue;
            }

            $buffer .= $char;
            $i++;
        }

        if ($buffer !== '') {
            $tokens[] = ['type' => 'text', 'value' => $buffer, 'name' => null];
        }

        return $tokens;
    }
}
