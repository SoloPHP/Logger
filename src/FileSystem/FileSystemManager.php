<?php

declare(strict_types=1);

namespace Solo\Logger\FileSystem;

class FileSystemManager
{
    /**
     * Ensure log directory exists
     */
    public function ensureLogDirectory(string $logFile): void
    {
        if ($logFile !== '' && !is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
    }

    /**
     * Write log line to file with locking
     */
    public function writeLogLine(string $logFile, string $logLine): void
    {
        file_put_contents(
            $logFile,
            $logLine,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Check if file exists
     */
    public function fileExists(string $file): bool
    {
        return file_exists($file);
    }

    /**
     * Get file size
     */
    public function getFileSize(string $file): int
    {
        return filesize($file);
    }
}
