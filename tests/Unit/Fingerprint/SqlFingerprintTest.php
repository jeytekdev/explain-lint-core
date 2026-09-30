<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Tests\Unit\Fingerprint;

use Jeytekdev\ExplainLint\Fingerprint\SqlFingerprint;
use PHPUnit\Framework\TestCase;

final class SqlFingerprintTest extends TestCase
{
    public function testDifferentLiteralsProduceTheSameFingerprint(): void
    {
        $a = SqlFingerprint::hash('SELECT * FROM users WHERE id = 1');
        $b = SqlFingerprint::hash('SELECT * FROM users WHERE id = 2');

        self::assertSame($a, $b);
    }

    public function testDifferentStringLiteralsProduceTheSameFingerprint(): void
    {
        $a = SqlFingerprint::hash("SELECT * FROM users WHERE email = 'a@example.com'");
        $b = SqlFingerprint::hash("SELECT * FROM users WHERE email = 'b@example.com'");

        self::assertSame($a, $b);
    }

    public function testDifferentLengthInListsCollapseToTheSameFingerprint(): void
    {
        $a = SqlFingerprint::hash('SELECT * FROM users WHERE id IN (1, 2, 3)');
        $b = SqlFingerprint::hash('SELECT * FROM users WHERE id IN (1, 2, 3, 4, 5)');

        self::assertSame($a, $b);
    }

    public function testPlaceholderInListsCollapseToTheSameFingerprintAsLiteralLists(): void
    {
        $a = SqlFingerprint::hash('SELECT * FROM users WHERE id IN (?, ?, ?)');
        $b = SqlFingerprint::hash('SELECT * FROM users WHERE id IN (1, 2, 3, 4)');

        self::assertSame($a, $b);
    }

    public function testDifferentQueriesProduceDifferentFingerprints(): void
    {
        $a = SqlFingerprint::hash('SELECT * FROM users WHERE id = 1');
        $b = SqlFingerprint::hash('SELECT * FROM orders WHERE id = 1');

        self::assertNotSame($a, $b);
    }

    public function testWhitespaceDifferencesDoNotAffectFingerprint(): void
    {
        $a = SqlFingerprint::hash("SELECT *\nFROM   users\nWHERE id = 1");
        $b = SqlFingerprint::hash('SELECT * FROM users WHERE id = 1');

        self::assertSame($a, $b);
    }

    public function testCaseDifferencesDoNotAffectFingerprint(): void
    {
        $a = SqlFingerprint::hash('select * from users where id = 1');
        $b = SqlFingerprint::hash('SELECT * FROM users WHERE id = 1');

        self::assertSame($a, $b);
    }

    public function testNamedAndPositionalPlaceholdersNormalizeConsistently(): void
    {
        $normalized = SqlFingerprint::normalize('SELECT * FROM users WHERE id = :id');

        self::assertSame('select * from users where id = ?', $normalized);
    }

    public function testOperatorsInsideStringLiteralsAreNotMistakenForSql(): void
    {
        $normalized = SqlFingerprint::normalize("SELECT * FROM logs WHERE message = 'WHERE id = 1 OR 1=1'");

        self::assertSame('select * from logs where message = ?', $normalized);
    }
}
