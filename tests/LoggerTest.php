<?php

declare(strict_types=1);

namespace Solo\Logger\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Solo\Logger\Formatter\JsonFormatter;
use Solo\Logger\Logger;

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
            'action' => 'login',
        ]);

        $this->assertStringContainsString('User 123 performed login', file_get_contents($this->testLogFile));
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

        $this->assertStringContainsString('Configuration test', file_get_contents($this->testLogFile));
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

        $this->assertStringContainsString('Custom level message', file_get_contents($this->testLogFile));
    }

    public function testSetFormatterSwapsOutput(): void
    {
        $logger = new Logger($this->testLogFile);
        $logger->setFormatter(new JsonFormatter());

        $logger->info('Job queued', ['id' => 7]);

        $content = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('"level":"info"', $content);
        $this->assertStringContainsString('"id":7', $content);
    }

    public function testLogCreatesDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/logger_newdir_' . uniqid();
        $logFile = $dir . '/test.log';

        $logger = new Logger($logFile);
        $logger->info('Directory creation test');

        $this->assertFileExists($logFile);

        unlink($logFile);
        rmdir($dir);
    }
}
