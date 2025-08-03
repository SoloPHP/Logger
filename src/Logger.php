<?php

declare(strict_types=1);

namespace Solo\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Solo\Logger\Configuration\LoggerConfiguration;
use Solo\Logger\FileSystem\FileSystemManager;
use Solo\Logger\Interpolation\ContextInterpolator;
use Solo\Logger\Rotation\LogRotator;

class Logger implements LoggerInterface
{
    private LoggerConfiguration $config;
    private FileSystemManager $fileSystem;
    private ContextInterpolator $interpolator;
    private LogRotator $rotator;

    /**
     * @param string $logFile Path to log file
     * @param string $timezone Timezone for log timestamps
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
        int $rotationInterval = 86400
    ) {
        $this->config = new LoggerConfiguration($logFile, $timezone);
        $this->fileSystem = new FileSystemManager();
        $this->interpolator = new ContextInterpolator();
        $this->rotator = new LogRotator($maxFileSize, $maxFiles, $rotationStrategy, $rotationInterval);
    }

    public function setLogFile(string $logFile): void
    {
        $this->config->setLogFile($logFile);
        $this->fileSystem->ensureLogDirectory($logFile);
    }

    public function setTimezone(string $timezone): void
    {
        $this->config->setTimezone($timezone);
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

        $this->fileSystem->ensureLogDirectory($logFile);
        $this->rotator->checkAndRotate($logFile);

        $timestamp = date('Y-m-d H:i:s');
        $interpolatedMessage = $this->interpolator->interpolate($message, $context);

        $logLine = sprintf(
            '[%s] %s: %s%s',
            $timestamp,
            strtoupper($level),
            $interpolatedMessage,
            PHP_EOL
        );

        $this->fileSystem->writeLogLine($logFile, $logLine);
    }
}
