<?php

declare(strict_types=1);

namespace Solo\Logger;

use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Solo\Logger\Clock\SystemClock;
use Solo\Logger\Formatter\FormatterInterface;
use Solo\Logger\Formatter\LineFormatter;
use Solo\Logger\Rotation\RotationOptions;
use Solo\Logger\Writer\FileWriter;
use Solo\Logger\Writer\StreamWriter;
use Solo\Logger\Writer\WriterInterface;

final class Logger extends AbstractLogger implements LevelAwareLoggerInterface
{
    private string $minLevel;

    public function __construct(
        private readonly WriterInterface $writer,
        private readonly FormatterInterface $formatter = new LineFormatter(),
        string $minLevel = LogLevel::DEBUG,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly ?string $channel = null,
    ) {
        // Configuration like a template, checked once: no formatter has to make it safe for each record.
        if ($channel !== null && preg_match('/\A[^\x00-\x1F\x7F]+\z/u', $channel) !== 1) {
            throw new \InvalidArgumentException('A channel name must be UTF-8 text without control characters.');
        }
        $this->setMinLevel($minLevel);
    }

    public static function file(
        string $path,
        string $minLevel = LogLevel::DEBUG,
        FormatterInterface $formatter = new LineFormatter(),
        RotationOptions $rotation = new RotationOptions(),
        string $timezone = 'UTC',
        int $directoryPermissions = 0755,
        ?int $filePermissions = null,
    ): self {
        $clock = new SystemClock(new \DateTimeZone($timezone));
        $writer = new FileWriter($path, $rotation, $clock, $directoryPermissions, $filePermissions);

        return new self($writer, $formatter, $minLevel, $clock);
    }

    public static function stream(
        string $url = 'php://stderr',
        string $minLevel = LogLevel::DEBUG,
        FormatterInterface $formatter = new LineFormatter(),
        string $timezone = 'UTC',
    ): self {
        return new self(new StreamWriter($url), $formatter, $minLevel, new SystemClock(new \DateTimeZone($timezone)));
    }

    /** The same output, threshold and clock under another channel; the two thresholds change independently. */
    public function withChannel(string $channel): self
    {
        return new self($this->writer, $this->formatter, $this->minLevel, $this->clock, $channel);
    }

    public function setMinLevel(string $level): void
    {
        Levels::assertValid($level);
        $this->minLevel = $level;
    }

    #[\Override]
    public function isHandling(string $level): bool
    {
        Levels::assertValid($level);
        return $this->meetsThreshold($level);
    }

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        Levels::assertValid($level);
        if (!$this->meetsThreshold($level)) {
            return;
        }

        $record = new LogRecord($level, (string) $message, $context, $this->clock->now(), $this->channel);
        $this->writer->write($this->formatter->format($record));
    }

    /** @param key-of<Levels::SEVERITY> $level */
    private function meetsThreshold(string $level): bool
    {
        return Levels::SEVERITY[$level] <= Levels::SEVERITY[$this->minLevel];
    }
}
