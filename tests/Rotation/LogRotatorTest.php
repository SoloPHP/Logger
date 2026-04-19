<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Rotation;

use PHPUnit\Framework\TestCase;
use Solo\Logger\Rotation\LogRotator;

class LogRotatorTest extends TestCase
{
    private string $testLogFile;
    private string $testLogDir;

    protected function setUp(): void
    {
        $this->testLogDir = sys_get_temp_dir() . '/logger_test';
        $this->testLogFile = $this->testLogDir . '/test.log';

        if (!is_dir($this->testLogDir)) {
            mkdir($this->testLogDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->testLogDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->testLogDir)) {
            rmdir($this->testLogDir);
        }
    }

    public function testSetRotationStrategyRejectsInvalid(): void
    {
        $rotator = new LogRotator();

        $this->expectException(\InvalidArgumentException::class);
        $rotator->setRotationStrategy('bogus');
    }

    public function testTimeRotationUsesFileMtimeAcrossInstances(): void
    {
        file_put_contents($this->testLogFile, 'content');
        touch($this->testLogFile, time() - 10);

        (new LogRotator(0, 3, 'time', 5))->checkAndRotate($this->testLogFile);

        $this->assertFileDoesNotExist($this->testLogFile);
    }

    public function testLogFileWithoutExtension(): void
    {
        $logFile = $this->testLogDir . '/app';
        file_put_contents($logFile, str_repeat('x', 150));

        (new LogRotator(100, 3, 'size'))->checkAndRotate($logFile);

        $this->assertFileDoesNotExist($logFile);
        $this->assertCount(1, glob($this->testLogDir . '/app_*') ?: []);
    }

    public function testRotationCollisionGetsUniqueSuffix(): void
    {
        $rotator = new LogRotator(100, 10, 'size');

        file_put_contents($this->testLogFile, str_repeat('x', 150));
        $rotator->checkAndRotate($this->testLogFile);

        file_put_contents($this->testLogFile, str_repeat('y', 150));
        $rotator->checkAndRotate($this->testLogFile);

        $this->assertCount(2, glob($this->testLogDir . '/test_*.log') ?: []);
    }

    public function testOldFilesCleanup(): void
    {
        $rotator = new LogRotator(100, 2, 'size');

        file_put_contents($this->testLogDir . '/test_2020-01-01_00-00-01.log', 'old1');
        sleep(1);
        file_put_contents($this->testLogDir . '/test_2020-01-01_00-00-02.log', 'old2');
        sleep(1);

        file_put_contents($this->testLogFile, str_repeat('x', 150));
        $rotator->checkAndRotate($this->testLogFile);

        $this->assertFileDoesNotExist($this->testLogFile);
        $this->assertLessThanOrEqual(2, count(glob($this->testLogDir . '/test_*.log') ?: []));
    }
}
