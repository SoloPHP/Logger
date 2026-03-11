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
        $this->assertStringContainsString('Test message', $content);
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
        $this->assertStringContainsString('Emergency message', $content);
        $this->assertStringContainsString('Alert message', $content);
        $this->assertStringContainsString('Critical message', $content);
        $this->assertStringContainsString('Error message', $content);
        $this->assertStringContainsString('Warning message', $content);
        $this->assertStringContainsString('Notice message', $content);
        $this->assertStringContainsString('Info message', $content);
        $this->assertStringContainsString('Debug message', $content);
    }

    public function testMinLevelFiltering(): void
    {
        $logger = new Logger($this->testLogFile);

        // Only WARNING and above should be written
        $logger->setMinLevel(LogLevel::WARNING);

        $logger->debug('Should be ignored');
        $logger->info('Should be ignored');
        $logger->warning('Should appear');
        $logger->error('Should appear');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringNotContainsString('Should be ignored', $content);
        $this->assertStringContainsString('Should appear', $content);
    }

    public function testContextInterpolation(): void
    {
        $logger = new Logger($this->testLogFile);
        $logger->info('User {user_id} performed {action}', [
            'user_id' => 123,
            'action' => 'login'
        ]);

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('User 123 performed login', $content);
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
        $this->assertStringContainsString('Stringable message', $content);
    }

    public function testLogMethod(): void
    {
        $logger = new Logger($this->testLogFile);
        $logger->log(LogLevel::INFO, 'Direct log message');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('Direct log message', $content);
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
        $this->assertStringContainsString('Configuration test', $content);
    }

    public function testSetMinLevelWithInvalidLevel(): void
    {
        $logger = new Logger($this->testLogFile);

        $this->expectException(\InvalidArgumentException::class);
        $logger->setMinLevel('invalid_level');
    }

    public function testUnknownLevelIsAllowed(): void
    {
        $logger = new Logger($this->testLogFile);
        $logger->log('custom_level', 'Custom level message');

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('Custom level message', $content);
    }

    public function testLogCreatesDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/logger_newdir_' . uniqid();
        $logFile = $dir . '/test.log';

        $logger = new Logger($logFile);
        $logger->info('Directory creation test');

        $this->assertFileExists($logFile);
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('Directory creation test', $content);

        unlink($logFile);
        rmdir($dir);
    }
}
