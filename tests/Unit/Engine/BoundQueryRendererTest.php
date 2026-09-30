<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Tests\Unit\Engine;

use Jeytekdev\ExplainLint\Engine\BoundQueryRenderer;
use PHPUnit\Framework\TestCase;

final class BoundQueryRendererTest extends TestCase
{
    private BoundQueryRenderer $renderer;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->renderer = new BoundQueryRenderer();
        $this->pdo = new \PDO('sqlite::memory:');
    }

    public function testReturnsSqlUnchangedWhenThereAreNoParams(): void
    {
        $sql = 'SELECT * FROM users';

        self::assertSame($sql, $this->renderer->render($sql, [], $this->pdo, 'mysql'));
    }

    public function testRendersPositionalPlaceholdersOneIndexed(): void
    {
        $sql = 'SELECT * FROM users WHERE id = ? AND name = ?';
        $params = [
            1 => ['value' => 42, 'type' => \PDO::PARAM_INT],
            2 => ['value' => "O'Brien", 'type' => \PDO::PARAM_STR],
        ];

        $rendered = $this->renderer->render($sql, $params, $this->pdo, 'mysql');

        self::assertSame("SELECT * FROM users WHERE id = 42 AND name = 'O''Brien'", $rendered);
    }

    public function testRendersZeroIndexedListParams(): void
    {
        $sql = 'SELECT * FROM users WHERE id = ?';
        $rendered = $this->renderer->render($sql, [42], $this->pdo, 'mysql');

        self::assertSame('SELECT * FROM users WHERE id = 42', $rendered);
    }

    public function testRendersNamedPlaceholders(): void
    {
        $sql = 'SELECT * FROM users WHERE id = :id';
        $rendered = $this->renderer->render($sql, ['id' => ['value' => 7, 'type' => \PDO::PARAM_INT]], $this->pdo, 'mysql');

        self::assertSame('SELECT * FROM users WHERE id = 7', $rendered);
    }

    public function testMysqlBooleanLiteralsRenderAsZeroOne(): void
    {
        $sql = 'SELECT * FROM users WHERE active = ?';
        $rendered = $this->renderer->render($sql, [true], $this->pdo, 'mysql');

        self::assertSame('SELECT * FROM users WHERE active = 1', $rendered);
    }

    public function testPostgresBooleanLiteralsRenderAsTrueFalse(): void
    {
        $sql = 'SELECT * FROM users WHERE active = ?';
        $rendered = $this->renderer->render($sql, [false], $this->pdo, 'pgsql');

        self::assertSame('SELECT * FROM users WHERE active = false', $rendered);
    }

    public function testNullValueRendersAsNullLiteral(): void
    {
        $sql = 'SELECT * FROM users WHERE deleted_at = ?';
        $rendered = $this->renderer->render($sql, [null], $this->pdo, 'mysql');

        self::assertSame('SELECT * FROM users WHERE deleted_at = NULL', $rendered);
    }

    public function testPlaceholderCharactersInsideStringLiteralsAreNotSubstituted(): void
    {
        $sql = "SELECT * FROM users WHERE note = 'what?' AND id = ?";
        $rendered = $this->renderer->render($sql, [1], $this->pdo, 'mysql');

        self::assertSame("SELECT * FROM users WHERE note = 'what?' AND id = 1", $rendered);
    }
}
