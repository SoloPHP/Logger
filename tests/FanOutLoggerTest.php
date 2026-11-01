<?php

declare(strict_types=1);

namespace Solo\Logger\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Solo\Logger\FanOutLogger;
use Solo\Logger\Formatter\JsonFormatter;
use Solo\Logger\Logger;
use Solo\Logger\Tests\Support\MemoryWriter;
use Solo\Logger\Tests\Support\SpyLogger;
use Solo\Logger\Writer\WriteException;

final class FanOutLoggerTest extends TestCase
{
    public function testEachLoggerKeepsItsFormatAndThreshold(): void
    {
        $all = new MemoryWriter();
        $errors = new MemoryWriter();
        $logger = new FanOutLogger(
            new Logger($all, new JsonFormatter()),
            new Logger($errors, minLevel: LogLevel::ERROR),
        );
        $logger->info('Started');
        $logger->error('Failed {id}', ['id' => 7]);

        self::assertCount(2, $all->records);
        self::assertSame('Failed 7', json_decode($all->records[1], true)['message']);
        self::assertCount(1, $errors->records);
        self::assertStringContainsString('ERROR: Failed 7', $errors->records[0]);
    }

    public function testEveryLoggerReceivesTheRecordWhenOneFails(): void
    {
        $outage = new WriteException('disk full');
        $first = new SpyLogger($outage);
        $second = new SpyLogger();
        try {
            (new FanOutLogger($first, $second))->error('Payment failed', ['order' => 7]);
            self::fail('Expected the output failure to be rethrown.');
        } catch (WriteException $e) {
            self::assertSame($outage, $e);
        }
        self::assertSame([['error', 'Payment failed', ['order' => 7]]], $second->calls);
    }

    public function testFirstFailureIsRethrownButABugWinsOverAnOutage(): void
    {
        $outage = new WriteException('disk full');
        $bug = new \LogicException('broken formatter');
        $cases = [
            [[$outage, $bug, new WriteException('later outage')], $bug],
            [[$bug, new \LogicException('later bug')], $bug],
            [[$outage, new WriteException('later outage')], $outage],
        ];
        foreach ($cases as [$failures, $expected]) {
            $loggers = array_map(static fn(\Throwable $failure): SpyLogger => new SpyLogger($failure), $failures);
            try {
                (new FanOutLogger(...$loggers))->warning('x');
                self::fail('Expected a failure.');
            } catch (\Throwable $e) {
                self::assertSame($expected, $e);
            }
            foreach ($loggers as $logger) {
                self::assertCount(1, $logger->calls);
            }
        }
    }

    public function testIsHandlingWhenAnyLoggerWouldWrite(): void
    {
        $logger = new FanOutLogger(
            new Logger(new MemoryWriter(), minLevel: LogLevel::ERROR),
            new Logger(new MemoryWriter(), minLevel: LogLevel::WARNING),
        );
        self::assertTrue($logger->isHandling(LogLevel::WARNING));
        self::assertFalse($logger->isHandling(LogLevel::INFO));

        $unknown = new FanOutLogger(new Logger(new MemoryWriter(), minLevel: LogLevel::ERROR), new NullLogger());
        self::assertTrue($unknown->isHandling(LogLevel::DEBUG));
    }

    public function testIsHandlingRejectsAnUnknownLevelBeforeAskingTheLoggers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FanOutLogger(new NullLogger()))->isHandling('fatal');
    }

    public function testNoLoggersWriteNothing(): void
    {
        $logger = new FanOutLogger();
        $logger->error('nowhere');
        self::assertFalse($logger->isHandling(LogLevel::EMERGENCY));
    }

    public function testLoggersCanBeSpreadFromAKeyedArray(): void
    {
        $spy = new SpyLogger();
        (new FanOutLogger(...['file' => $spy, 'stderr' => $spy]))->info('twice');
        self::assertCount(2, $spy->calls);
    }
}
