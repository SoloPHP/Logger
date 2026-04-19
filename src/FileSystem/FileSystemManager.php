<?php

declare(strict_types=1);

namespace Solo\Logger\FileSystem;

final class FileSystemManager
{
    private string $lastEnsuredDir = '';

    public function __construct(
        private readonly int $directoryPermissions = 0755,
    ) {
    }

    /**
     * Ensure the log directory exists, creating it recursively if necessary.
     * Caches the last confirmed directory to avoid a stat() on every write.
     */
    public function ensureLogDirectory(string $logFile): void
    {
        if ($logFile === '') {
            return;
        }

        $dir = dirname($logFile);
        if ($dir === '' || $dir === $this->lastEnsuredDir) {
            return;
        }

        if (is_dir($dir)) {
            $this->lastEnsuredDir = $dir;
            return;
        }

        if (!@mkdir($dir, $this->directoryPermissions, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create log directory "%s"', $dir));
        }

        $this->lastEnsuredDir = $dir;
    }

    /**
     * Append a line to the log file with an exclusive lock.
     */
    public function writeLogLine(string $logFile, string $logLine): void
    {
        $bytes = @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

        if ($bytes === false) {
            throw new \RuntimeException(sprintf('Unable to write to log file "%s"', $logFile));
        }
    }
}
