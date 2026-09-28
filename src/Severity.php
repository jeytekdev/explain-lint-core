<?php

declare(strict_types=1);

namespace ExplainLint;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Error => 2,
        };
    }
}
