<?php

declare(strict_types=1);

namespace Solo\Logger\Configuration;

class LoggerConfiguration
{
    private string $logFile;

    public function __construct(string $logFile = '')
    {
        $this->logFile = $logFile;
    }

    public function getLogFile(): string
    {
        return $this->logFile;
    }

    public function setLogFile(string $logFile): void
    {
        $this->logFile = $logFile;
    }
}
