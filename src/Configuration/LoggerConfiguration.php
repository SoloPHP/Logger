<?php

declare(strict_types=1);

namespace Solo\Logger\Configuration;

class LoggerConfiguration
{
    private string $logFile;
    private string $timezone;

    public function __construct(string $logFile = '', string $timezone = '')
    {
        $this->logFile = $logFile;
        $this->setTimezone($timezone);
    }

    public function getLogFile(): string
    {
        return $this->logFile;
    }

    public function setLogFile(string $logFile): void
    {
        $this->logFile = $logFile;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): void
    {
        $defaultTimezone = 'Europe/Moscow';
        $this->timezone = $timezone ?: $defaultTimezone;
        date_default_timezone_set($this->timezone);
    }
}
