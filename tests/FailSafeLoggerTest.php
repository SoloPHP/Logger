<?php

declare(strict_types=1);

namespace Solo\Logger\Tests;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Solo\Logger\FailSafeLogger;
use Solo\Logger\Logger;
use Solo\Logger\Tests\Support\MemoryWriter;
use Solo\Logger\Tests\Support\SpyLogger;
use Solo\Logger\Tests\Support\TemporaryDirectoryTestCase;
use Solo\Logger\Writer\WriteException;

final class FailSafeLoggerTest extends TemporaryDirectoryTestCase
{
    public function testOutputFailureIsReportedInsteadOfThrown(): void
    {
        $outage = new WriteException('disk full');
        $reported = [];
        $report = static function (WriteException $e) use (&$reported): void {
            $reported[] = $e;
        };
        (new FailSafeLogger(new SpyLogger($outage), $report))->error('Payment failed');
        self::assertSame([$outage], $reported);
    }

    public function testFailuresGoToThePhpErrorLogByDefault(): void
    {
        $log = $this->directory . '/php-errors.log';
        $previous = ini_set('error_log', $log);
        try {
            (new FailSafeLogger(new SpyLogger(new WriteException('disk full'))))->error('Payment failed');
        } finally {
            ini_set('error_log', (string) $previous);
        }
        self::assertStringContainsString('Logging failed: disk full', (string) file_get_contents($log));
    }

    public function testBugsStillThrow(): void
    {
        $bug = new \LogicException('broken formatter');
        try {
            (new FailSafeLogger(new SpyLogger($bug)))->error('x');
            self::fail('Expected the bug to be thrown.');
        } catch (\LogicException $e) {
            self::assertSame($bug, $e);
        }
        $this->expectException(InvalidArgumentException::class);
        (new FailSafeLogger(new Logger(new MemoryWriter())))->log('fatal', 'x');
    }

    public function testIsHandlingAsksTheWrappedLogger(): void
    {
        $logger = new FailSafeLogger(new Logger(new MemoryWriter(), minLevel: LogLevel::WARNING));
        self::assertTrue($logger->isHandling(LogLevel::ERROR));
        self::assertFalse($logger->isHandling(LogLevel::INFO));
        self::assertTrue((new FailSafeLogger(new NullLogger()))->isHandling(LogLevel::DEBUG));
        $this->expectException(InvalidArgumentException::class);
        (new FailSafeLogger(new NullLogger()))->isHandling('fatal');
    }
}
