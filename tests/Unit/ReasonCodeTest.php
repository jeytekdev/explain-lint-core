<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Tests\Unit;

use Jeytekdev\ExplainLint\ReasonCode;
use PHPUnit\Framework\TestCase;

final class ReasonCodeTest extends TestCase
{
    public function testEveryReasonCodeHasARecommendation(): void
    {
        foreach (ReasonCode::cases() as $reasonCode) {
            self::assertNotSame(
                '',
                trim($reasonCode->recommendation()),
                sprintf('%s must have a non-empty recommendation', $reasonCode->name)
            );
        }
    }

    public function testEveryReasonCodeHasADefaultSeverity(): void
    {
        foreach (ReasonCode::cases() as $reasonCode) {
            self::assertNotNull($reasonCode->defaultSeverity());
        }
    }
}
