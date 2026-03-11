<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Configuration;

use PHPUnit\Framework\TestCase;
use Solo\Logger\Configuration\LoggerConfiguration;

class LoggerConfigurationTest extends TestCase
{
    public function testDefaultTimezone(): void
    {
        $config = new LoggerConfiguration();
        $this->assertEquals('Europe/Moscow', $config->getTimezone());
    }

    public function testCustomTimezone(): void
    {
        $config = new LoggerConfiguration('', 'UTC');
        $this->assertEquals('UTC', $config->getTimezone());
    }

    public function testSetTimezone(): void
    {
        $config = new LoggerConfiguration();
        $config->setTimezone('Asia/Tokyo');
        $this->assertEquals('Asia/Tokyo', $config->getTimezone());
    }

    public function testGetAndSetLogFile(): void
    {
        $config = new LoggerConfiguration('/tmp/test.log');
        $this->assertEquals('/tmp/test.log', $config->getLogFile());

        $config->setLogFile('/tmp/other.log');
        $this->assertEquals('/tmp/other.log', $config->getLogFile());
    }
}
