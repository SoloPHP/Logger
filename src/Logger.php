<?php

declare(strict_types=1);

namespace Solo\Logger;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Solo\Logger\Configuration\LoggerConfiguration;
use Solo\Logger\FileSystem\FileSystemManager;
use Solo\Logger\Formatter\FormatterInterface;
use Solo\Logger\Formatter\LineFormatter;
use Solo\Logger\Rotation\LogRotator;

class Logger implements LoggerInterface
{
    private LoggerConfiguration $config;
    private FileSystemManager $fileSystem;
    private FormatterInterface $formatter;
    private LogRotator $rotator;
    /**
     * Minimal log level that will be recorded. Levels below this threshold will be ignored.
     */
    private string $minLevel = LogLevel::DEBUG;

    /**
     * Severity ranking for PSR-3 levels (lower value = higher severity)
     */
    private const LEVEL_ORDER = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT => 1,
        LogLevel::CRITICAL => 2,
        LogLevel::ERROR => 3,
        LogLevel::WARNING => 4,
        LogLevel::NOTICE => 5,
        LogLevel::INFO => 6,
        LogLevel::DEBUG => 7,
    ];

    /**
     * @param string $logFile Path to log file
     * @param string $timezone Timezone for rotation file timestamps
     * @param int $maxFileSize Maximum file size in bytes (0 = no limit)
     * @param int $maxFiles Maximum number of rotation files (0 = no limit)
     * @param string $rotationStrategy Rotation strategy: 'size', 'time', 'both'
     * @param int $rotationInterval Rotation interval in seconds (for time-based rotation)
     */
    public function __construct(
        string $logFile = '',
        string $timezone = '',
        int $maxFileSize = 0,
        int $maxFiles = 0,
        string $rotationStrategy = 'size',
        int $rotationInterval = 86400,
        ?FormatterInterface $formatter = null
    ) {
        $this->config = new LoggerConfiguration($logFile);
        $this->fileSystem = new FileSystemManager();
        $this->formatter = $formatter ?? new LineFormatter();
        $this->rotator = new LogRotator($maxFileSize, $maxFiles, $rotationStrategy, $rotationInterval, $timezone);
    }

    public function setFormatter(FormatterInterface $formatter): void
    {
        $this->formatter = $formatter;
    }

    public function setLogFile(string $logFile): void
    {
        $this->config->setLogFile($logFile);
        $this->fileSystem->ensureLogDirectory($logFile);
    }

    public function setTimezone(string $timezone): void
    {
        $this->rotator->setTimezone($timezone);
    }

    public function setMaxFileSize(int $maxFileSize): void
    {
        $this->rotator->setMaxFileSize($maxFileSize);
    }

    public function setMaxFiles(int $maxFiles): void
    {
        $this->rotator->setMaxFiles($maxFiles);
    }

    public function setRotationStrategy(string $rotationStrategy): void
    {
        $this->rotator->setRotationStrategy($rotationStrategy);
    }

    public function setRotationInterval(int $rotationInterval): void
    {
        $this->rotator->setRotationInterval($rotationInterval);
    }

    /**
     * Set minimal log level threshold.
     * Messages with lower severity will be ignored.
     */
    public function setMinLevel(string $level): void
    {
        if (!isset(self::LEVEL_ORDER[$level])) {
            throw new \InvalidArgumentException(sprintf('Invalid log level "%s"', $level));
        }

        $this->minLevel = $level;
    }

    private function isLevelAllowed(string $level): bool
    {
        // If unknown level, allow it to pass to maintain backward compatibility
        if (!isset(self::LEVEL_ORDER[$level])) {
            return true;
        }

        return self::LEVEL_ORDER[$level] <= self::LEVEL_ORDER[$this->minLevel];
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $logFile = $this->config->getLogFile();

        if ($logFile === '') {
            return;
        }

        // Filter by minimal level first — formatter call + directory/rotation checks are wasted work otherwise.
        if (!$this->isLevelAllowed($level)) {
            return;
        }

        $this->fileSystem->ensureLogDirectory($logFile);
        $this->rotator->checkAndRotate($logFile);

        $line = $this->formatter->format((string) $level, $message, $context, new DateTimeImmutable());

        $this->fileSystem->writeLogLine($logFile, $line . PHP_EOL);
    }
}
