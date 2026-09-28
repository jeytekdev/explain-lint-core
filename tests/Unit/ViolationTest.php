<?php

declare(strict_types=1);

namespace ExplainLint\Tests\Unit;

use ExplainLint\ReasonCode;
use ExplainLint\Severity;
use ExplainLint\Violation;
use PHPUnit\Framework\TestCase;

final class ViolationTest extends TestCase
{
    public function testRecommendationDelegatesToReasonCode(): void
    {
        $violation = $this->violation(ReasonCode::Filesort);

        self::assertSame(ReasonCode::Filesort->recommendation(), $violation->recommendation());
    }

    public function testRecommendationIsUnaffectedByCustomMessage(): void
    {
        $violation = $this->violation(ReasonCode::Filesort, 'a custom describe() override');

        self::assertSame('a custom describe() override', $violation->describe());
        self::assertSame(ReasonCode::Filesort->recommendation(), $violation->recommendation());
    }

    private function violation(ReasonCode $reasonCode, ?string $message = null): Violation
    {
        return new Violation(
            fingerprint: 'abc123',
            normalizedSql: 'select * from orders where status = ?',
            tables: ['orders'],
            reasonCode: $reasonCode,
            severity: Severity::Warning,
            evidence: [],
            engine: 'mysql',
            connectionName: 'default',
            message: $message,
        );
    }
}
