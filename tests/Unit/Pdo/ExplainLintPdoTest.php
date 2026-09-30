<?php

declare(strict_types=1);

namespace Jeytekdev\ExplainLint\Tests\Unit\Pdo;

use Jeytekdev\ExplainLint\Pdo\ExplainLintPdo;
use Jeytekdev\ExplainLint\Recorder\QueryRecorder;
use PHPUnit\Framework\TestCase;

final class ExplainLintPdoTest extends TestCase
{
    protected function setUp(): void
    {
        QueryRecorder::instance()->clear();
    }

    public function testWrappingAnExistingPdoReusesTheSameConnection(): void
    {
        $pdo = new \PDO('sqlite::memory:');

        $wrapper = new ExplainLintPdo($pdo, connectionName: 'default');

        self::assertSame($pdo, $wrapper->connection());
    }

    public function testQueryOnAWrappedConnectionIsCapturedAgainstThatSameConnection(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $wrapper = new ExplainLintPdo($pdo, connectionName: 'yii');

        $wrapper->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $statement = $wrapper->prepare('INSERT INTO users (name) VALUES (?)');
        $statement->execute(['Ada']);

        $captured = QueryRecorder::instance()->all();
        $inserts = array_values(array_filter($captured, static fn ($q) => str_starts_with($q->sql, 'INSERT')));

        self::assertCount(1, $inserts);
        self::assertSame($pdo, $inserts[0]->connection);
        self::assertSame('yii', $inserts[0]->connectionName);
        self::assertSame(['Ada'], array_column($inserts[0]->params, 'value'));
    }
}
