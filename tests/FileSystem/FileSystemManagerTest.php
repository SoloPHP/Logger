<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\FileSystem;

use PHPUnit\Framework\TestCase;
use Solo\Logger\FileSystem\FileSystemManager;

class FileSystemManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/fs_mgr_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->recursiveRemove($this->tempDir);
        }
    }

    private function recursiveRemove(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @chmod($path, 0644);
                @unlink($path);
            }
            return;
        }
        @chmod($path, 0755);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->recursiveRemove($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }

    public function testEnsureLogDirectoryNoOpOnEmptyPath(): void
    {
        (new FileSystemManager())->ensureLogDirectory('');

        $this->assertTrue(true);
    }

    public function testEnsureLogDirectoryThrowsWhenCreateFails(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Cannot reliably test unwritable paths as root.');
        }

        mkdir($this->tempDir, 0755, true);
        $readonly = $this->tempDir . '/readonly';
        mkdir($readonly, 0500, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unable to create log directory/');

        try {
            (new FileSystemManager())->ensureLogDirectory($readonly . '/nested/test.log');
        } finally {
            @chmod($readonly, 0755);
        }
    }

    public function testWriteLogLineThrowsOnFailure(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Cannot reliably test unwritable paths as root.');
        }

        mkdir($this->tempDir, 0755, true);
        $dir = $this->tempDir . '/ro';
        mkdir($dir, 0500, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unable to write/');

        try {
            (new FileSystemManager())->writeLogLine($dir . '/out.log', "x\n");
        } finally {
            @chmod($dir, 0755);
        }
    }
}
