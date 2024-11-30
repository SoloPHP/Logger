<?php declare(strict_types=1);

namespace Solo;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class Logger implements LoggerInterface
{
    /** @var string */
    private $logFile;

    /**
     * @param string $logFile
     * @param string $timezone
     */
    public function __construct(string $logFile = '', string $timezone = '')
    {
        $this->setTimezone($timezone);
        $this->logFile = $logFile;
    }

    /**
     * @param string $logFile
     */
    public function setLogFile(string $logFile): void
    {
        $this->logFile = $logFile;
        $this->checkAndCreateLogDir();
    }

    /**
     * @param string $timezone
     */
    public function setTimezone(string $timezone): void
    {
        $defaultTimezone = 'Europe/Moscow';
        date_default_timezone_set($timezone ?: $defaultTimezone);
    }

    private function checkAndCreateLogDir(): void
    {
        if ($this->logFile !== '' && !is_dir(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0755, true);
        }
    }

    /**
     * @param string|mixed $message
     * @param array<string, mixed> $context
     */
    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * @param string|mixed $message
     * @param array<string, mixed> $context
     */
    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * @param string|mixed $message
     * @param array<string, mixed> $context
     */
    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * @param string|mixed $message
     * @param array<string, mixed> $context
     */
    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * @param string|mixed $message
     * @param array<string, mixed> $context
     */
    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @param string|mixed $message
     * @param array<string, mixed> $context
     */
    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * @param string|mixed $message
     * @param array<string, mixed> $context
     */
    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * @param string|mixed $message
     * @param array<string, mixed> $context
     */
    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * @param string $level
     * @param string|mixed $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        if ($this->logFile === '') {
            return;
        }

        $this->checkAndCreateLogDir();

        $timestamp = date('Y-m-d H:i:s');
        $message = $this->interpolate($message, $context);

        $logLine = sprintf(
            '[%s] %s: %s%s',
            $timestamp,
            strtoupper($level),
            $message,
            PHP_EOL
        );

        file_put_contents(
            $this->logFile,
            $logLine,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * @param string|mixed $message
     * @param array<string, mixed> $context
     * @return string
     */
    private function interpolate($message, array $context = []): string
    {
        $replace = [];

        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        return strtr($message, $replace);
    }
}