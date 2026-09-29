<?php

declare(strict_types=1);

namespace ExplainLint\Tests\Unit\Console;

use ExplainLint\Console\InstallCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallCommandTest extends TestCase
{
    private string $originalCwd;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd() ?: '.';
        $this->projectDir = sys_get_temp_dir() . '/explain-lint-install-test-' . uniqid();
        mkdir($this->projectDir);
        chdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeDirectory($this->projectDir);
    }

    public function testDefaultRunRegistersThePhpunitExtensionAndCreatesTheConfig(): void
    {
        file_put_contents($this->projectDir . '/phpunit.xml', '<?xml version="1.0"?><phpunit></phpunit>');

        $tester = new CommandTester(new InstallCommand());
        $tester->execute([]);

        self::assertFileExists($this->projectDir . '/explain-lint.php');
        self::assertStringContainsString(
            'ExplainLint\PHPUnit\ExplainLintExtension',
            (string) file_get_contents($this->projectDir . '/phpunit.xml')
        );
    }

    public function testConfigOnlySkipsThePhpunitXmlRewrite(): void
    {
        $original = '<?xml version="1.0"?><phpunit></phpunit>';
        file_put_contents($this->projectDir . '/phpunit.xml', $original);

        $tester = new CommandTester(new InstallCommand());
        $tester->execute(['--config-only' => true]);

        self::assertFileExists($this->projectDir . '/explain-lint.php');
        self::assertSame($original, file_get_contents($this->projectDir . '/phpunit.xml'));
    }

    public function testConfigOnlyDoesNotRequireAPhpunitXmlToExist(): void
    {
        $tester = new CommandTester(new InstallCommand());
        $tester->execute(['--config-only' => true]);

        self::assertFileExists($this->projectDir . '/explain-lint.php');
        self::assertFileDoesNotExist($this->projectDir . '/phpunit.xml');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
