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
        $this->cleanupTestFiles();
    }

    private function cleanupTestFiles(): void
    {
        $files = glob($this->testLogDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->testLogDir)) {
            rmdir($this->testLogDir);
        }
    }

    public function testSizeBasedRotation(): void
    {
        $rotator = new LogRotator(100, 3, 'size');

        // Create a file larger than the limit
        file_put_contents($this->testLogFile, str_repeat('x', 150));

        $rotator->checkAndRotate($this->testLogFile);

        $this->assertFileDoesNotExist($this->testLogFile);
        $this->assertGreaterThan(0, count(glob($this->testLogDir . '/*')));
    }

    public function testTimeBasedRotation(): void
    {
        $rotator = new LogRotator(0, 3, 'time', 1); // 1 second interval

        file_put_contents($this->testLogFile, 'test content');

        // Wait a bit and then rotate
        sleep(2);
        $rotator->checkAndRotate($this->testLogFile);

        $this->assertFileDoesNotExist($this->testLogFile);
        $this->assertGreaterThan(0, count(glob($this->testLogDir . '/*')));
    }

    public function testNoRotationWhenNotNeeded(): void
    {
        $rotator = new LogRotator(1000, 3, 'size');

        file_put_contents($this->testLogFile, 'small content');

        $rotator->checkAndRotate($this->testLogFile);

        $this->assertFileExists($this->testLogFile);
        $this->assertEquals('small content', file_get_contents($this->testLogFile));
    }

    public function testConfigurationMethods(): void
    {
        $rotator = new LogRotator();

        $rotator->setMaxFileSize(2048);
        $rotator->setMaxFiles(10);
        $rotator->setRotationStrategy('both');
        $rotator->setRotationInterval(7200);

        // Test that methods don't throw errors
        $this->assertTrue(true);
    }
}
