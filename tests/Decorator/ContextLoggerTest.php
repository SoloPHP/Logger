<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Decorator;

use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Solo\Logger\Decorator\ContextLogger;
use Solo\Logger\Decorator\FailSafeLogger;
use Solo\Logger\Logger;
use Solo\Logger\Tests\Support\MemoryWriter;
use Solo\Logger\Tests\Support\SpyLogger;

final class ContextLoggerTest extends TestCase
{
    public function testSharedContextComesFirstAndACallKeyWins(): void
    {
        $spy = new SpyLogger();
        $logger = new ContextLogger($spy, ['request_id' => 'req-1', 'worker' => 'w1']);
        $logger->info('Shipped', ['order' => 7, 'request_id' => 'req-2']);
        self::assertSame(
            [['info', 'Shipped', ['request_id' => 'req-2', 'worker' => 'w1', 'order' => 7]]],
            $spy->calls,
        );
    }

    public function testIsHandlingAsksTheWrappedLoggers(): void
    {
        $logger = new ContextLogger(
            new FailSafeLogger(new Logger(new MemoryWriter(), minLevel: LogLevel::ERROR)),
            ['request_id' => 'req-1'],
        );
        self::assertTrue($logger->isHandling(LogLevel::ERROR));
        self::assertFalse($logger->isHandling(LogLevel::WARNING));
        self::assertTrue((new ContextLogger(new NullLogger(), []))->isHandling(LogLevel::DEBUG));
        $this->expectException(InvalidArgumentException::class);
        (new ContextLogger(new NullLogger(), []))->isHandling('fatal');
    }
}
