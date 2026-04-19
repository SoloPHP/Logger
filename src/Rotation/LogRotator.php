<?php

declare(strict_types=1);

namespace Solo\Logger\Rotation;

final class LogRotator
{
    public const STRATEGY_SIZE = 'size';
    public const STRATEGY_TIME = 'time';
    public const STRATEGY_BOTH = 'both';

    private const ALLOWED_STRATEGIES = [
        self::STRATEGY_SIZE,
        self::STRATEGY_TIME,
        self::STRATEGY_BOTH,
    ];

    private int $maxFileSize;
    private int $maxFiles;
    private string $rotationStrategy;
    private int $rotationInterval;
    private \DateTimeZone $timezone;

    public function __construct(
        int $maxFileSize = 0,
        int $maxFiles = 0,
        string $rotationStrategy = self::STRATEGY_SIZE,
        int $rotationInterval = 86400,
        string $timezone = ''
    ) {
        $this->assertValidStrategy($rotationStrategy);

        $this->maxFileSize = max(0, $maxFileSize);
        $this->maxFiles = max(0, $maxFiles);
        $this->rotationStrategy = $rotationStrategy;
        $this->rotationInterval = max(1, $rotationInterval);
        $this->timezone = new \DateTimeZone($timezone !== '' ? $timezone : date_default_timezone_get());
    }

    public function setMaxFileSize(int $maxFileSize): void
    {
        $this->maxFileSize = max(0, $maxFileSize);
    }

    public function setMaxFiles(int $maxFiles): void
    {
        $this->maxFiles = max(0, $maxFiles);
    }

    public function setRotationStrategy(string $rotationStrategy): void
    {
        $this->assertValidStrategy($rotationStrategy);
        $this->rotationStrategy = $rotationStrategy;
    }

    public function setRotationInterval(int $rotationInterval): void
    {
        $this->rotationInterval = max(1, $rotationInterval);
    }

    public function setTimezone(string $timezone): void
    {
        $this->timezone = new \DateTimeZone($timezone !== '' ? $timezone : date_default_timezone_get());
    }

    /**
     * Check if rotation is needed and perform it.
     */
    public function checkAndRotate(string $logFile): void
    {
        if ($logFile === '' || !is_file($logFile)) {
            return;
        }

        if ($this->shouldRotate($logFile)) {
            $this->rotateLogFile($logFile);
        }
    }

    private function shouldRotate(string $logFile): bool
    {
        $sizeCheck = $this->rotationStrategy === self::STRATEGY_SIZE
            || $this->rotationStrategy === self::STRATEGY_BOTH;

        if ($sizeCheck && $this->maxFileSize > 0) {
            $size = @filesize($logFile);
            if ($size !== false && $size >= $this->maxFileSize) {
                return true;
            }
        }

        $timeCheck = $this->rotationStrategy === self::STRATEGY_TIME
            || $this->rotationStrategy === self::STRATEGY_BOTH;

        if ($timeCheck) {
            $mtime = @filemtime($logFile);
            if ($mtime !== false && (time() - $mtime) >= $this->rotationInterval) {
                return true;
            }
        }

        return false;
    }

    /**
     * Perform log file rotation.
     */
    private function rotateLogFile(string $logFile): void
    {
        $logDir = dirname($logFile);
        $logName = basename($logFile);
        $logExt = pathinfo($logName, PATHINFO_EXTENSION);
        $logBase = pathinfo($logName, PATHINFO_FILENAME);

        if ($this->maxFiles > 0) {
            $this->cleanOldLogFiles($logDir, $logBase, $logExt);
        }

        $target = $this->resolveRotatedPath($logDir, $logBase, $logExt);

        // Silently tolerate race conditions where another process rotated first.
        @rename($logFile, $target);
    }

    /**
     * Build a collision-safe timestamped path. On same-second rotations (sub-second
     * loops, multi-process races) appends an hrtime-derived suffix.
     */
    private function resolveRotatedPath(string $logDir, string $logBase, string $logExt): string
    {
        $timestamp = (new \DateTimeImmutable('now', $this->timezone))->format('Y-m-d_H-i-s');

        $baseCandidate = $this->buildRotatedName($logDir, $logBase, $logExt, $timestamp);
        if (!file_exists($baseCandidate)) {
            return $baseCandidate;
        }

        $suffix = substr((string) hrtime(true), -6);
        return $this->buildRotatedName($logDir, $logBase, $logExt, $timestamp . '_' . $suffix);
    }

    private function buildRotatedName(string $logDir, string $logBase, string $logExt, string $stamp): string
    {
        return $logExt !== ''
            ? $logDir . '/' . $logBase . '_' . $stamp . '.' . $logExt
            : $logDir . '/' . $logBase . '_' . $stamp;
    }

    /**
     * Remove oldest rotated files to stay within the retention limit.
     */
    private function cleanOldLogFiles(string $logDir, string $logBase, string $logExt): void
    {
        $pattern = $logExt !== ''
            ? $logDir . '/' . $logBase . '_*.' . $logExt
            : $logDir . '/' . $logBase . '_*';

        $files = glob($pattern) ?: [];

        if (count($files) < $this->maxFiles) {
            return;
        }

        usort($files, static function (string $a, string $b): int {
            return (int) (@filemtime($a) ?: 0) <=> (int) (@filemtime($b) ?: 0);
        });

        $filesToDelete = array_slice($files, 0, count($files) - $this->maxFiles + 1);
        foreach ($filesToDelete as $file) {
            @unlink($file);
        }
    }

    private function assertValidStrategy(string $strategy): void
    {
        if (!in_array($strategy, self::ALLOWED_STRATEGIES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid rotation strategy "%s". Allowed: %s.',
                $strategy,
                implode(', ', self::ALLOWED_STRATEGIES),
            ));
        }
    }
}
