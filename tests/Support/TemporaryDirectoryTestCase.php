<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Support;

use PHPUnit\Framework\TestCase;

abstract class TemporaryDirectoryTestCase extends TestCase
{
    protected string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/solo-logger-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    /** @return list<string> the archives rotated from the log at $path, without the active file and the lock */
    protected function archives(string $path): array
    {
        return array_values(array_filter(glob($path . '.*') ?: [], static fn(string $file): bool =>
            !str_ends_with($file, '.lock')));
    }

    protected static function skipIfRoot(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0) {
            self::markTestSkipped('Permission checks need a non-root POSIX user.');
        }
    }

    protected static function skipOnWindows(string $reason): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped($reason);
        }
    }

    private function removeDirectory(string $directory): void
    {
        chmod($directory, 0700);
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
