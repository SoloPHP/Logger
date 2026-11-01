<?php

declare(strict_types=1);

namespace Solo\Logger\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use Psr\Clock\ClockInterface;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Solo\Logger\Context\ContextNormalizer;
use Solo\Logger\Formatter\FormatterInterface;
use Solo\Logger\Formatter\JsonFormatter;
use Solo\Logger\Formatter\LineFormatter;
use Solo\Logger\Logger;
use Solo\Logger\Tests\Fixtures\ThrowingStringable;
use Solo\Logger\Tests\Support\MemoryWriter;
use Solo\Logger\Tests\Support\MutableClock;
use Solo\Logger\Tests\Support\TemporaryDirectoryTestCase;
use Solo\Logger\Writer\WriterInterface;

final class LoggerTest extends TemporaryDirectoryTestCase
{
    public function testPsrMethodsForwardAllEightLevelsAndContext(): void
    {
        $writer = new MemoryWriter();
        $clock = new MutableClock();
        $logger = new Logger($writer, new JsonFormatter(), clock: $clock);
        $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
        foreach ($levels as $level) {
            $logger->$level('Event', ['id' => 42]);
        }
        $records = array_map(
            static fn(string $record): array => json_decode($record, true, flags: JSON_THROW_ON_ERROR),
            $writer->records,
        );
        self::assertSame($levels, array_column($records, 'level'));
        foreach ($records as $record) {
            self::assertSame('Event', $record['message']);
            self::assertSame(['id' => 42], $record['context']);
            self::assertSame($clock->time->format(ContextNormalizer::DEFAULT_DATE_FORMAT), $record['time']);
        }
    }

    public function testFilteredRecordsDoNotTouchMessageClockFormatterOrWriter(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects(self::never())->method('write');
        $formatter = $this->createMock(FormatterInterface::class);
        $formatter->expects(self::never())->method('format');
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::never())->method('now');
        $message = new ThrowingStringable(new \LogicException('A filtered message must remain lazy.'));

        (new Logger($writer, $formatter, LogLevel::WARNING, $clock))->info($message);
    }

    public function testThresholdCanChangeWithoutReconfiguringOutput(): void
    {
        $writer = new MemoryWriter();
        $logger = new Logger($writer, new LineFormatter('{message}'), LogLevel::WARNING);
        $logger->info('ignored');
        $logger->warning('warning');
        $logger->setMinLevel(LogLevel::DEBUG);
        $logger->debug('debug');
        self::assertSame(['warning', 'debug'], $writer->records);
    }

    #[DataProvider('invalidLevels')]
    public function testInvalidLevelsThrowThePsrException(mixed $level): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects(self::never())->method('write');
        $this->expectException(InvalidArgumentException::class);
        (new Logger($writer, minLevel: LogLevel::EMERGENCY))->log($level, 'invalid');
    }

    public static function invalidLevels(): array
    {
        return [['custom'], ['INFO'], [''], [0], [null], [[]], [new \stdClass()]];
    }

    public function testInvalidLevelMessageNamesTheLevelAndTheAlternatives(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Unknown log level "custom"; expected one of: '
            . 'emergency, alert, critical, error, warning, notice, info, debug.',
        );
        (new Logger($this->createStub(WriterInterface::class)))->log('custom', 'invalid');
    }

    public function testInvalidMinimumLevelDoesNotChangeThePreviousThreshold(): void
    {
        $writer = new MemoryWriter();
        $logger = new Logger($writer, new LineFormatter('{message}'), LogLevel::ERROR);
        try {
            $logger->setMinLevel('custom');
            self::fail('Expected invalid level rejection.');
        } catch (InvalidArgumentException) {
            $logger->warning('ignored');
            $logger->error('written');
        }
        self::assertSame(['written'], $writer->records);
    }

    public function testFormattingFailureHasNoFilesystemSideEffects(): void
    {
        $path = $this->directory . '/not-created/app.log';
        $formatter = $this->createStub(FormatterInterface::class);
        $formatter->method('format')->willThrowException(new \RuntimeException('Formatter failed.'));
        try {
            Logger::file($path, formatter: $formatter)->error('failed');
            self::fail('Expected formatter exception.');
        } catch (\RuntimeException $e) {
            self::assertSame('Formatter failed.', $e->getMessage());
            self::assertDirectoryDoesNotExist(dirname($path));
        }
    }

    public function testFileFactoryUsesItsTimezoneAndWritesNdjson(): void
    {
        $path = $this->directory . '/nested/app.log';
        $logger = Logger::file($path, formatter: new JsonFormatter(), timezone: 'Asia/Tokyo');
        $logger->info("First\nmessage");
        $logger->error('Second');
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertCount(2, $lines);
        self::assertStringEndsWith('+09:00', json_decode($lines[0], true)['time']);
        self::assertSame("First\nmessage", json_decode($lines[0], true)['message']);
    }

    public function testFileFactoryPassesTheModesToTheWriter(): void
    {
        self::skipOnWindows('Windows has no POSIX file modes.');
        $path = $this->directory . '/private/app.log';
        $umask = umask(022);
        try {
            Logger::file($path, directoryPermissions: 0750, filePermissions: 0640)->info('x');
        } finally {
            umask($umask);
        }
        clearstatcache();
        self::assertSame([0750, 0640], [fileperms(dirname($path)) & 0777, fileperms($path) & 0777]);
    }

    public function testIsHandlingAnswersForTheCurrentThreshold(): void
    {
        $logger = new Logger(new MemoryWriter(), minLevel: LogLevel::WARNING);
        self::assertSame([true, true, false], [
            $logger->isHandling(LogLevel::ERROR),
            $logger->isHandling(LogLevel::WARNING),
            $logger->isHandling(LogLevel::INFO),
        ]);
        $logger->setMinLevel(LogLevel::DEBUG);
        self::assertTrue($logger->isHandling(LogLevel::DEBUG));
        $this->expectException(InvalidArgumentException::class);
        $logger->isHandling('custom');
    }

    public function testChannelIsStampedOnEveryRecordAndCopiesShareTheOutput(): void
    {
        $writer = new MemoryWriter();
        $logger = new Logger($writer, new JsonFormatter(), LogLevel::INFO, channel: 'app');
        $payments = $logger->withChannel('payments');
        $logger->info('one');
        $payments->info('two');
        $payments->debug('filtered: the copy starts with the same threshold');
        $logger->setMinLevel(LogLevel::ERROR);
        $payments->info('three: thresholds change independently');

        self::assertSame(['app', 'payments', 'payments'], array_map(
            static fn(string $record): string => json_decode($record, true)['channel'],
            $writer->records,
        ));
    }

    #[DataProvider('invalidChannels')]
    public function testChannelMustBeTextWithoutControlCharacters(string $channel): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A channel name must be UTF-8 text without control characters.');
        new Logger(new MemoryWriter(), channel: $channel);
    }

    public static function invalidChannels(): array
    {
        return ['empty' => [''], 'line break' => ["pay\nments"], 'escape' => ["pay\e[31m"], 'bad UTF-8' => ["pay\xB1"]];
    }

    #[RequiresFunction('proc_open')]
    public function testStreamFactoryWritesToStandardErrorByDefault(): void
    {
        $code = sprintf(
            'require %s; Solo\Logger\Logger::stream()->warning("Disk at {percent}%%", ["percent" => 91]);',
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
        );
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame('', $stdout);
        self::assertMatchesRegularExpression(
            '~\A\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}\+00:00\] WARNING: Disk at 91% \{"percent":91\}\n\z~',
            $stderr,
        );
    }

    public function testStreamFactoryTakesAUrlAFormatterAndATimezone(): void
    {
        $path = $this->directory . '/stream.log';
        $logger = Logger::stream($path, LogLevel::INFO, new JsonFormatter(), 'Asia/Tokyo');
        $logger->debug('filtered');
        $logger->info('written');
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertCount(1, $lines);
        self::assertStringEndsWith('+09:00', json_decode($lines[0], true)['time']);
    }
}
