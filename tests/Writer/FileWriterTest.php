<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Writer;

use PHPUnit\Framework\Attributes\DataProvider;
use Solo\Logger\Rotation\RotationOptions;
use Solo\Logger\Rotation\Schedule;
use Solo\Logger\Tests\Support\MutableClock;
use Solo\Logger\Tests\Support\TemporaryDirectoryTestCase;
use Solo\Logger\Writer\FileWriter;
use Solo\Logger\Writer\WriteException;

final class FileWriterTest extends TemporaryDirectoryTestCase
{
    public function testSizeRotationUsesFreshSizeAndIncludesTheIncomingRecord(): void
    {
        $path = $this->directory . '/app.log';
        $writer = new FileWriter($path, new RotationOptions(maxBytes: 8));
        $writer->write('abc');
        $writer->write('def');
        self::assertSame("abc\ndef\n", file_get_contents($path));
        $writer->write('ghi');

        $archives = $this->archives($path);
        self::assertCount(1, $archives);
        self::assertSame("abc\ndef\n", file_get_contents($archives[0]));
        self::assertSame("ghi\n", file_get_contents($path));
    }

    public function testOversizedRecordIsPreservedWithoutEmptyArchives(): void
    {
        $path = $this->directory . '/app';
        $writer = new FileWriter($path, new RotationOptions(maxBytes: 3));
        $writer->write('oversized');
        self::assertSame([], $this->archives($path));
        $writer->write('x');
        self::assertSame("oversized\n", file_get_contents($this->archives($path)[0]));
        self::assertSame("x\n", file_get_contents($path));
    }

    public function testTimeRotationPersistsAcrossInstancesDuringContinuousTraffic(): void
    {
        $path = $this->directory . '/app.log';
        $clock = new MutableClock();
        $rotation = new RotationOptions(intervalSeconds: 5);
        for ($i = 0; $i < 11; ++$i) {
            (new FileWriter($path, $rotation, $clock))->write((string) $i);
            $clock->advance(1);
        }
        $archives = $this->archives($path);
        self::assertCount(2, $archives);
        self::assertSame("0\n1\n2\n3\n4\n", file_get_contents($archives[0]));
        self::assertSame("5\n6\n7\n8\n9\n", file_get_contents($archives[1]));
        self::assertSame("10\n", file_get_contents($path));
    }

    public function testSizeRotationStartsANewTimePeriod(): void
    {
        $path = $this->directory . '/app.log';
        $clock = new MutableClock();
        $writer = new FileWriter($path, new RotationOptions(maxBytes: 8, intervalSeconds: 10), $clock);
        $writer->write('first');
        $clock->advance(9);
        $writer->write('next'); // Size rotates at second 9.
        $clock->advance(1);
        $writer->write('x'); // Original time deadline must not rotate again.
        self::assertCount(1, $this->archives($path));
        $clock->advance(9);
        $writer->write('y'); // New time deadline at second 19.
        self::assertCount(2, $this->archives($path));
        self::assertSame("y\n", file_get_contents($path));
    }

    #[DataProvider('schedules')]
    public function testScheduleRotatesOnTheFirstWriteInANewPeriod(
        Schedule $schedule,
        string $first,
        string $last,
        string $next,
    ): void {
        $path = $this->directory . '/app.log';
        $clock = new MutableClock(new \DateTimeImmutable($first));
        $writer = new FileWriter($path, new RotationOptions(schedule: $schedule), $clock);
        $writer->write('first');
        $clock->time = new \DateTimeImmutable($last);
        $writer->write('last');
        self::assertSame([], $this->archives($path));
        $clock->time = new \DateTimeImmutable($next);
        $writer->write('next');
        self::assertSame(["first\nlast\n"], array_map(file_get_contents(...), $this->archives($path)));
        self::assertSame("next\n", file_get_contents($path));
    }

    public static function schedules(): array
    {
        return [
            'hourly' => [Schedule::Hourly, '2026-11-01T10:00:00Z', '2026-11-01T10:59:59Z', '2026-11-01T11:00:00Z'],
            'daily' => [Schedule::Daily, '2026-11-01T00:00:00Z', '2026-11-01T23:59:59Z', '2026-11-02T00:00:00Z'],
            'weekly, Monday to Sunday' => [
                Schedule::Weekly,
                '2026-11-02T00:00:00Z',
                '2026-11-08T23:59:59Z',
                '2026-11-09T00:00:00Z',
            ],
            'monthly' => [Schedule::Monthly, '2026-11-01T00:00:00Z', '2026-11-30T23:59:59Z', '2026-12-01T00:00:00Z'],
        ];
    }

    public function testDailyScheduleFollowsLocalMidnightWhenDaylightSavingTimeEnds(): void
    {
        // In Berlin, 25 October 2026 lasts 25 hours: at 03:00 the clocks go back to 02:00.
        $berlin = new \DateTimeZone('Europe/Berlin');
        $path = $this->directory . '/app.log';
        $clock = new MutableClock(new \DateTimeImmutable('2026-10-25 00:30', $berlin));
        $writer = new FileWriter($path, new RotationOptions(schedule: Schedule::Daily), $clock);
        $writer->write('first');
        // 24 hours later it is still 25 October: an interval of 86400 seconds would rotate here.
        $clock->time = new \DateTimeImmutable('2026-10-25 23:30', $berlin);
        $writer->write('same day');
        self::assertSame([], $this->archives($path));
        $clock->time = new \DateTimeImmutable('2026-10-26 00:00:01', $berlin);
        $writer->write('next day');
        $archives = $this->archives($path);
        self::assertSame(["first\nsame day\n"], array_map(file_get_contents(...), $archives));
        self::assertStringEndsWith('.2026-10-26_00-00-01.000000+0100.0', $archives[0]);
    }

    public function testHourlyScheduleTellsApartTheHourThatRepeats(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');
        $at = static fn(string $utc): \DateTimeImmutable => (new \DateTimeImmutable($utc))->setTimezone($berlin);
        $path = $this->directory . '/app.log';
        $clock = new MutableClock($at('2026-10-25T00:15:00Z')); // 02:15 summer time
        $writer = new FileWriter($path, new RotationOptions(schedule: Schedule::Hourly), $clock);
        $writer->write('summer');
        $clock->time = $at('2026-10-25T00:45:00Z'); // 02:45 summer time
        $writer->write('same hour');
        $clock->time = $at('2026-10-25T01:15:00Z'); // 02:15 once more, winter time
        $writer->write('winter');
        self::assertSame(["summer\nsame hour\n"], array_map(file_get_contents(...), $this->archives($path)));
        self::assertSame("winter\n", file_get_contents($path));
    }

    public function testSizeRotationDoesNotMoveTheNextScheduledRotation(): void
    {
        $path = $this->directory . '/app.log';
        $clock = new MutableClock(new \DateTimeImmutable('2026-11-01T10:00:00Z'));
        $writer = new FileWriter($path, new RotationOptions(maxBytes: 12, schedule: Schedule::Daily), $clock);
        $writer->write('morning');
        $clock->time = new \DateTimeImmutable('2026-11-01T12:00:00Z');
        $writer->write('noon'); // 8 + 5 bytes: a size rotation, which starts a new period at noon
        $clock->time = new \DateTimeImmutable('2026-11-01T23:59:59Z');
        $writer->write('late');
        $clock->time = new \DateTimeImmutable('2026-11-02T00:00:01Z');
        $writer->write('x'); // 10 + 2 bytes fit; midnight rotates, not 24 hours after noon
        $archives = $this->archives($path);
        sort($archives);
        self::assertSame(["morning\n", "noon\nlate\n"], array_map(file_get_contents(...), $archives));
        self::assertSame("x\n", file_get_contents($path));
    }

    public function testExistingFileGetsAnExplicitPeriodOnFirstAdoption(): void
    {
        $path = $this->directory . '/app.log';
        file_put_contents($path, "existing\n");
        touch($path, 1);
        $clock = new MutableClock();
        $writer = new FileWriter($path, new RotationOptions(intervalSeconds: 5), $clock);
        $writer->write('adopted');
        self::assertSame([], $this->archives($path));
        $clock->advance(5);
        $writer->write('new');
        self::assertSame("existing\nadopted\n", file_get_contents($this->archives($path)[0]));
    }

    public function testRetentionKeepsExactlyTheNewestArchivesAtTheSameTimestamp(): void
    {
        $path = $this->directory . '/app.log';
        $clock = new MutableClock();
        $writer = new FileWriter($path, new RotationOptions(maxBytes: 1, maxFiles: 2), $clock);
        foreach (range(1, 15) as $id) {
            $writer->write((string) $id);
        }
        $archives = $this->archives($path);
        self::assertCount(2, $archives);
        $contents = array_map(file_get_contents(...), $archives);
        sort($contents);
        self::assertSame(["13\n", "14\n"], $contents);
        self::assertSame("15\n", file_get_contents($path));
    }

    public function testRetentionNeverDeletesOtherChannelsOrLegacyArchives(): void
    {
        $path = $this->directory . '/app[1].log';
        $other = [
            $this->directory . '/app[1]_worker.log',
            $this->directory . '/app[1]_2020-01-01_00-00-00.log',
            $path . '.not-an-archive',
            $path . '.2026-99-31_12-00-00.000000+0000.0',
            $this->directory . '/app1.log.2026-10-31_12-00-00.000000+0000.0',
        ];
        foreach ($other as $file) {
            file_put_contents($file, 'keep');
        }
        $writer = new FileWriter($path, new RotationOptions(maxBytes: 1, maxFiles: 1));
        $writer->write('one');
        $writer->write('two');
        $writer->write('three');
        foreach ($other as $file) {
            self::assertSame('keep', file_get_contents($file));
        }
    }

    public function testArchiveTimezoneMatchesTheInjectedClock(): void
    {
        $path = $this->directory . '/app.log';
        $clock = new MutableClock(new \DateTimeImmutable('2026-10-31T12:00:00.123456+09:00'));
        $writer = new FileWriter($path, new RotationOptions(maxBytes: 1), $clock);
        $writer->write('first');
        $writer->write('second');
        self::assertStringContainsString('2026-10-31_12-00-00.123456+0900', $this->archives($path)[0]);
    }

    public function testRelativePathIsAnchoredAtConstruction(): void
    {
        $cwd = getcwd();
        try {
            chdir($this->directory);
            $writer = new FileWriter('nested/app.log');
            chdir(sys_get_temp_dir());
            $writer->write('anchored');
            self::assertSame("anchored\n", file_get_contents($this->directory . '/nested/app.log'));
        } finally {
            chdir($cwd);
        }
    }

    public function testDeletedDirectoryIsRecreatedByTheSameWriter(): void
    {
        $path = $this->directory . '/nested/app.log';
        $writer = new FileWriter($path);
        $writer->write('first');
        unlink($path);
        unlink($path . '.lock');
        rmdir(dirname($path));
        $writer->write('second');
        self::assertSame("second\n", file_get_contents($path));
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidPathsAreRejected(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileWriter($path);
    }

    public static function invalidPaths(): array
    {
        return [[''], ["bad\0path"], ['php://stderr'], ['.'], ['..'], ['/tmp/']];
    }

    public function testDirectoryCreationFailureIsReported(): void
    {
        $path = $this->directory . '/file';
        file_put_contents($path, 'not a directory');
        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Unable to create log directory');
        (new FileWriter($path . '/nested/app.log'))->write('failed');
    }

    #[DataProvider('lockAndLogFiles')]
    public function testDirectoryCannotStandInForTheLockOrTheLog(string $suffix, string $message): void
    {
        $path = $this->directory . '/app.log';
        mkdir($path . $suffix);
        $this->expectException(WriteException::class);
        $this->expectExceptionMessage($message);
        (new FileWriter($path))->write('failed');
    }

    #[DataProvider('lockAndLogFiles')]
    public function testSymlinkIsRejectedWithoutTouchingItsTarget(string $suffix, string $message): void
    {
        self::skipOnWindows('Creating symlinks on Windows may require additional privileges.');
        $path = $this->directory . '/app.log';
        $victim = $this->directory . '/victim.txt';
        file_put_contents($victim, 'precious');
        symlink($victim, $path . $suffix);
        try {
            (new FileWriter($path, new RotationOptions(intervalSeconds: 60)))->write('attack');
            self::fail('Expected the symlink to be rejected.');
        } catch (WriteException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame('precious', file_get_contents($victim));
    }

    public static function lockAndLogFiles(): array
    {
        return [
            'lock file' => ['.lock', 'Expected a regular lock file'],
            'log file' => ['', 'Expected a regular log file'],
        ];
    }

    public function testOpenedFileMustBeTheEntryAtThePath(): void
    {
        self::skipOnWindows('Creating symlinks on Windows may require additional privileges.');
        // A symlink swapped in between the check and fopen() cannot be staged deterministically,
        // so the identity check that catches that race is exercised directly.
        $isSameFile = new \ReflectionMethod(FileWriter::class, 'isSameFile');
        $victim = $this->directory . '/victim.txt';
        file_put_contents($victim, 'precious');
        symlink($victim, $this->directory . '/link');
        $opened = stat($victim);
        self::assertTrue($isSameFile->invoke(null, $opened, lstat($victim)));
        self::assertFalse($isSameFile->invoke(null, $opened, lstat($this->directory . '/link')));
        self::assertFalse($isSameFile->invoke(null, $opened, false));
    }

    public function testArchivesWithoutRetentionStillGetDistinctOrderedNames(): void
    {
        $path = $this->directory . '/app.log';
        $writer = new FileWriter($path, new RotationOptions(maxBytes: 1), new MutableClock());
        foreach (['one', 'two', 'three', 'four'] as $record) {
            $writer->write($record);
        }
        $archives = $this->archives($path);
        sort($archives);
        self::assertSame(['0', '1', '2'], array_map(static fn(string $file): string => substr($file, -1), $archives));
        self::assertSame(["one\n", "two\n", "three\n"], array_map(file_get_contents(...), $archives));
    }

    public function testUnopenableLockFileIsReported(): void
    {
        self::skipIfRoot();
        $path = $this->directory . '/app.log';
        touch($path . '.lock');
        chmod($path . '.lock', 0400);
        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Unable to open lock file');
        (new FileWriter($path))->write('failed');
    }

    public function testFailedRotationKeepsTheRecordAndRetriesOnTheNextWrite(): void
    {
        self::skipIfRoot();
        $path = $this->directory . '/app.log';
        $writer = new FileWriter($path, new RotationOptions(maxBytes: 1, maxFiles: 1));
        $writer->write('one');
        $writer->write('two');
        chmod($this->directory, 0500);
        try {
            $writer->write('three');
            self::fail('Expected a rotation error.');
        } catch (WriteException $e) {
            self::assertStringContainsString('Unable to rotate', $e->getMessage());
            self::assertSame("one\n", file_get_contents($this->archives($path)[0]));
            self::assertSame("two\nthree\n", file_get_contents($path));
        } finally {
            chmod($this->directory, 0700);
        }
        $lock = fopen($path . '.lock', 'c+b');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
        $writer->write('recovered');
        $archives = $this->archives($path);
        self::assertCount(1, $archives);
        self::assertSame("two\nthree\n", file_get_contents($archives[0]));
        self::assertSame("recovered\n", file_get_contents($path));
    }

    public function testUnlistableDirectoryStopsTheRotationButNotTheRecord(): void
    {
        self::skipIfRoot();
        $path = $this->directory . '/app.log';
        $writer = new FileWriter($path, new RotationOptions(maxBytes: 1, maxFiles: 1));
        $writer->write('one');
        // Writable and searchable, not readable: retention cannot list the archives.
        chmod($this->directory, 0300);
        try {
            $writer->write('two');
            self::fail('Expected a rotation error.');
        } catch (WriteException $e) {
            self::assertStringContainsString('Unable to list log directory', $e->getMessage());
        } finally {
            chmod($this->directory, 0700);
        }
        self::assertSame("one\ntwo\n", file_get_contents($path));
        self::assertSame([], $this->archives($path));
    }

    public function testFilePermissionsApplyToEveryFileTheWriterCreates(): void
    {
        self::skipOnWindows('Windows has no POSIX file modes.');
        $umask = umask(022);
        try {
            $path = $this->directory . '/app.log';
            $writer = new FileWriter($path, new RotationOptions(maxBytes: 1), filePermissions: 0640);
            $writer->write('one');
            $writer->write('two');
            clearstatcache();
            self::assertSame(
                [0640, 0640, 0640],
                array_map(static fn(string $file): int => fileperms($file) & 0777, [
                    $path,
                    $path . '.lock',
                    $this->archives($path)[0],
                ]),
            );

            // Like the directory mode, the file mode is filtered by the umask.
            umask(027);
            $other = $this->directory . '/other.log';
            (new FileWriter($other, filePermissions: 0660))->write('x');
            clearstatcache();
            self::assertSame(0640, fileperms($other) & 0777);
        } finally {
            umask($umask);
        }
    }

    public function testExistingFilesKeepTheirMode(): void
    {
        self::skipOnWindows('Windows has no POSIX file modes.');
        $path = $this->directory . '/app.log';
        touch($path);
        chmod($path, 0644);
        $umask = umask(022);
        try {
            (new FileWriter($path, filePermissions: 0600))->write('x');
        } finally {
            umask($umask);
        }
        clearstatcache();
        self::assertSame([0644, 0600], [fileperms($path) & 0777, fileperms($path . '.lock') & 0777]);
    }

    #[DataProvider('invalidPermissions')]
    public function testPermissionsOutOfRangeAreRejected(int $directory, ?int $file): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileWriter($this->directory . '/app.log', directoryPermissions: $directory, filePermissions: $file);
    }

    public static function invalidPermissions(): array
    {
        return [
            'negative directory mode' => [-1, null],
            'directory mode with special bits' => [01000, null],
            'directory the owner cannot write' => [0555, null],
            'directory the owner cannot enter' => [0650, null],
            'negative file mode' => [0755, -1],
            'file mode with special bits' => [0755, 04644],
            'file the owner cannot write' => [0755, 0444],
            'file the owner cannot read' => [0755, 0200],
        ];
    }

    public function testUnwritableActiveFileIsReported(): void
    {
        self::skipIfRoot();
        $path = $this->directory . '/app.log';
        $writer = new FileWriter($path);
        $writer->write('keep');
        chmod($path, 0400);
        try {
            $writer->write('cannot write');
            self::fail('Expected an output error.');
        } catch (WriteException $e) {
            self::assertStringContainsString('Unable to open log file', $e->getMessage());
            self::assertSame("keep\n", file_get_contents($path));
        } finally {
            chmod($path, 0600);
        }
    }

    public function testRetentionIgnoresSymlinksThatLookLikeArchives(): void
    {
        self::skipOnWindows('Creating symlinks on Windows may require additional privileges.');
        $path = $this->directory . '/app.log';
        $other = $this->directory . '/another.log';
        $link = $path . '.2020-01-01_00-00-00.000000+0000.0';
        file_put_contents($other, 'keep');
        symlink($other, $link);
        $writer = new FileWriter($path, new RotationOptions(maxBytes: 1, maxFiles: 1));
        $writer->write('one');
        $writer->write('two');
        $writer->write('three');
        self::assertTrue(is_link($link));
        self::assertSame('keep', file_get_contents($other));
    }
}
