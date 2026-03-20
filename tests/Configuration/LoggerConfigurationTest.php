<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Configuration;

use PHPUnit\Framework\TestCase;
use Solo\Logger\Configuration\LoggerConfiguration;

class LoggerConfigurationTest extends TestCase
{
    public function testDefaultLogFile(): void
    {
        $config = new LoggerConfiguration();
        $this->assertEquals('', $config->getLogFile());
    }

    public function testCustomLogFile(): void
    {
        $config = new LoggerConfiguration('/tmp/test.log');
        $this->assertEquals('/tmp/test.log', $config->getLogFile());
    }

    public function testSetLogFile(): void
    {
        $config = new LoggerConfiguration();
        $config->setLogFile('/tmp/other.log');
        $this->assertEquals('/tmp/other.log', $config->getLogFile());
    }
}
