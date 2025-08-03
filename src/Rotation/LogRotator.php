<?php

declare(strict_types=1);

namespace Solo\Logger\Rotation;

class LogRotator
{
    private int $maxFileSize;
    private int $maxFiles;
    private string $rotationStrategy;
    private int $rotationInterval;
    private int $lastRotationTime;

    public function __construct(
        int $maxFileSize = 0,
        int $maxFiles = 0,
        string $rotationStrategy = 'size',
        int $rotationInterval = 86400
    ) {
        $this->maxFileSize = $maxFileSize;
        $this->maxFiles = $maxFiles;
        $this->rotationStrategy = $rotationStrategy;
        $this->rotationInterval = $rotationInterval;
        $this->lastRotationTime = time();
    }

    public function setMaxFileSize(int $maxFileSize): void
    {
        $this->maxFileSize = $maxFileSize;
    }

    public function setMaxFiles(int $maxFiles): void
    {
        $this->maxFiles = $maxFiles;
    }

    public function setRotationStrategy(string $rotationStrategy): void
    {
        $this->rotationStrategy = $rotationStrategy;
    }

    public function setRotationInterval(int $rotationInterval): void
    {
        $this->rotationInterval = $rotationInterval;
    }

    /**
     * Check if rotation is needed and perform it
     */
    public function checkAndRotate(string $logFile): void
    {
        if ($logFile === '' || !file_exists($logFile)) {
            return;
        }

        $shouldRotate = false;

        // Check file size rotation
        if ($this->rotationStrategy === 'size' || $this->rotationStrategy === 'both') {
            if ($this->maxFileSize > 0 && filesize($logFile) >= $this->maxFileSize) {
                $shouldRotate = true;
            }
        }

        // Check time-based rotation
        if ($this->rotationStrategy === 'time' || $this->rotationStrategy === 'both') {
            if (time() - $this->lastRotationTime >= $this->rotationInterval) {
                $shouldRotate = true;
            }
        }

        if ($shouldRotate) {
            $this->rotateLogFile($logFile);
        }
    }

    /**
     * Perform log file rotation
     */
    private function rotateLogFile(string $logFile): void
    {
        $logDir = dirname($logFile);
        $logName = basename($logFile);
        $logExt = pathinfo($logName, PATHINFO_EXTENSION);
        $logBase = pathinfo($logName, PATHINFO_FILENAME);

        // Remove old files if limit exceeded
        if ($this->maxFiles > 0) {
            $this->cleanOldLogFiles($logDir, $logBase, $logExt);
        }

        // Rename current file
        $timestamp = date('Y-m-d_H-i-s');
        $rotatedFile = $logDir . '/' . $logBase . '_' . $timestamp . '.' . $logExt;

        if (file_exists($logFile)) {
            rename($logFile, $rotatedFile);
        }

        $this->lastRotationTime = time();
    }

    /**
     * Remove old log files
     */
    private function cleanOldLogFiles(string $logDir, string $logBase, string $logExt): void
    {
        $pattern = $logDir . '/' . $logBase . '_*.' . $logExt;
        $files = glob($pattern);

        if (count($files) >= $this->maxFiles) {
            // Sort files by modification time (oldest first)
            usort($files, fn($a, $b) => filemtime($a) - filemtime($b));

            // Remove oldest files
            $filesToDelete = array_slice($files, 0, count($files) - $this->maxFiles + 1);
            foreach ($filesToDelete as $file) {
                unlink($file);
            }
        }
    }
}
