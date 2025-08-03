<?php

declare(strict_types=1);

namespace Solo\Logger\Tests;

use PHPUnit\Framework\TestCase;
use Solo\Logger\Logger;
use Psr\Log\LogLevel;

class LoggerTest extends TestCase
{
    private string $testLogFile;

    protected function setUp(): void
    {
        $this->testLogFile = sys_get_temp_dir() . '/test_logger.log';
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }
    }

    public function testBasicLogging(): void
    {
        $logger = new Logger($this->testLogFile);
        $logger->info('Test message');

        $this->assertFileExists($this->testLogFile);
        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('INFO: Test message', $content);
    }

    public function testAllLogLevels(): void
    {
        $logger = new Logger($this->testLogFile);

        $logger->emergency('Emergency message');
        $logger->alert('Alert message');
        $logger->critical('Critical message');
        $logger->error('Error message');
        $logger->warning('Warning message');
        $logger->notice('Notice message');
        $logger->info('Info message');
        $logger->debug('Debug message');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('EMERGENCY: Emergency message', $content);
        $this->assertStringContainsString('ALERT: Alert message', $content);
        $this->assertStringContainsString('CRITICAL: Critical message', $content);
        $this->assertStringContainsString('ERROR: Error message', $content);
        $this->assertStringContainsString('WARNING: Warning message', $content);
        $this->assertStringContainsString('NOTICE: Notice message', $content);
        $this->assertStringContainsString('INFO: Info message', $content);
        $this->assertStringContainsString('DEBUG: Debug message', $content);
    }

    public function testContextInterpolation(): void
    {
        $logger = new Logger($this->testLogFile);
        $logger->info('User {user_id} performed {action}', [
            'user_id' => 123,
            'action' => 'login'
        ]);

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('INFO: User 123 performed login', $content);
    }

    public function testStringableObject(): void
    {
        $logger = new Logger($this->testLogFile);

        $stringableObject = new class implements \Stringable {
            public function __toString(): string
            {
                return 'Stringable message';
            }
        };

        $logger->info($stringableObject);

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('INFO: Stringable message', $content);
    }

    public function testLogMethod(): void
    {
        $logger = new Logger($this->testLogFile);
        $logger->log(LogLevel::INFO, 'Direct log message');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('INFO: Direct log message', $content);
    }

    public function testEmptyLogFile(): void
    {
        $logger = new Logger('');
        $logger->info('This should not create a file');

        $this->assertFileDoesNotExist($this->testLogFile);
    }

    public function testConfigurationMethods(): void
    {
        $logger = new Logger($this->testLogFile);

        $logger->setLogFile($this->testLogFile);
        $logger->setTimezone('UTC');
        $logger->setMaxFileSize(1024);
        $logger->setMaxFiles(5);
        $logger->setRotationStrategy('size');
        $logger->setRotationInterval(3600);

        $logger->info('Configuration test');

        $this->assertFileExists($this->testLogFile);
        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('INFO: Configuration test', $content);
    }
}
