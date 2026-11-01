<?php

declare(strict_types=1);

namespace Solo\Logger\Writer;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Solo\Logger\Clock\SystemClock;
use Solo\Logger\Rotation\RotationOptions;
use Solo\Logger\Rotation\Schedule;

/**
 * Local file output. All cooperating writers must use the same path and rotation options.
 * The persistent .lock file owns both the lock and the start of the active rotation period.
 *
 * @phpstan-type Archive array{path: string, time: DateTimeImmutable, sequence: int}
 */
final readonly class FileWriter implements WriterInterface
{
    private const string ARCHIVE_DATE_FORMAT = 'Y-m-d_H-i-s.uO';

    private string $path;

    /**
     * @param int $directoryPermissions the mode of the directories it creates, minus the umask; the owner keeps
     *                                  read, write and search
     * @param int|null $filePermissions the mode of the log and lock files it creates, minus the umask; the owner
     *                                  keeps read and write; null leaves it to the umask
     */
    public function __construct(
        string $path,
        private RotationOptions $rotation = new RotationOptions(),
        private ClockInterface $clock = new SystemClock(),
        private int $directoryPermissions = 0755,
        private ?int $filePermissions = null,
    ) {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            throw new \InvalidArgumentException('A non-empty local log file path is required.');
        }
        if (
            in_array(basename($path), ['.', '..'], true)
            || in_array(substr($path, -1), ['/', DIRECTORY_SEPARATOR], true)
        ) {
            throw new \InvalidArgumentException('The log path must name a file.');
        }
        // The writer must keep working: it lists, renames and creates in the directory, and reopens both files.
        $modes = ['Directory' => [$directoryPermissions, 0700], 'File' => [$filePermissions, 0600]];
        foreach ($modes as $kind => [$mode, $owner]) {
            if ($mode !== null && ($mode < 0 || $mode > 0777 || ($mode & $owner) !== $owner)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s permissions must be between 0000 and 0777 and include %04o for the owner.',
                    $kind,
                    $owner,
                ));
            }
        }

        $absolute = DIRECTORY_SEPARATOR === '\\'
            ? preg_match('~^(?:[A-Za-z]:)?[\\\\/]~', $path) === 1
            : str_starts_with($path, '/');
        $cwd = getcwd();
        if (!$absolute && $cwd === false) {
            throw new \RuntimeException('Unable to resolve the current directory.');
        }
        $this->path = $absolute ? $path : $cwd . DIRECTORY_SEPARATOR . $path;
    }

    #[\Override]
    public function write(string $record): void
    {
        $this->ensureDirectory();
        [$lock] = $this->open($this->path . '.lock', 'c+b', 'lock file');

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new WriteException(sprintf('Unable to lock "%s".', $this->path));
            }
            $this->writeLocked($lock, $record . "\n");
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path);
        clearstatcache();
        if (!is_dir($directory) && !@mkdir($directory, $this->directoryPermissions, true) && !is_dir($directory)) {
            throw new WriteException(sprintf('Unable to create log directory "%s".', $directory));
        }
    }

    /** @param resource $lock */
    private function writeLocked($lock, string $data): void
    {
        [$stream, $stat] = $this->open($this->path, 'ab', 'log file');
        $archives = null;
        $rotationFailure = null;
        try {
            $now = $this->clock->now();
            $timed = $this->rotation->intervalSeconds !== null || $this->rotation->schedule !== null;
            // Read for an empty file too: a file without a period gets one, so the first write starts it.
            $began = $timed ? $this->periodStart($lock, $stat, $now->getTimestamp()) : null;
            $sizeExceeded = $this->rotation->maxBytes !== null
                && strlen($data) > $this->rotation->maxBytes - $stat['size'];

            // A single oversized record is kept intact; never create an empty archive.
            if ($stat['size'] > 0 && ($sizeExceeded || ($began !== null && $this->periodEnded($began, $now)))) {
                fclose($stream);
                $stream = null;
                try {
                    $archives = $this->rotate($now);
                } catch (WriteException $e) {
                    // Housekeeping must not cost the record: it goes to the file that could not be archived,
                    // and the next write tries the rotation again.
                    $rotationFailure = $e;
                }
                [$stream, $stat] = $this->open($this->path, 'ab', 'log file');
                if ($archives !== null && $timed) {
                    $this->savePeriod($lock, $stat, $now->getTimestamp());
                }
            }

            StreamIo::writeAll($stream, $data, $this->path);
        } finally {
            if ($stream !== null) {
                fclose($stream);
            }
        }

        if ($rotationFailure !== null) {
            throw $rotationFailure;
        }
        // Never delete old archives before the replacement archive and record exist.
        if ($archives !== null && $this->rotation->maxFiles !== null) {
            $this->prune($archives, $this->rotation->maxFiles);
        }
    }

    /**
     * Open a regular file, never through a symlink: the writer must not append to, or truncate, what a link points
     * to. PHP cannot open without following links, so the entry is checked before fopen(), and the opened file must
     * be that entry, or the file fopen() created.
     *
     * @return array{resource, array<int|string, int>} the stream and its fstat()
     */
    private function open(string $path, string $mode, string $kind): array
    {
        clearstatcache();
        $entry = @lstat($path);
        if ($entry !== false && !self::isRegularFile($entry)) {
            throw new WriteException(sprintf('Expected a regular %s at "%s".', $kind, $path));
        }
        $stream = @fopen($path, $mode);
        if ($stream === false) {
            throw new WriteException(sprintf('Unable to open %s "%s".', $kind, $path));
        }
        $opened = fstat($stream);
        if ($opened === false || !self::isSameFile($opened, $entry ?: @lstat($path))) {
            fclose($stream);
            throw new WriteException(sprintf('Expected a regular %s at "%s".', $kind, $path));
        }
        // Only a file created here gets the configured mode; an existing one keeps the mode it has. The new file
        // already carries the umask, so narrowing its mode applies it without umask(), which briefly changes the
        // umask of the whole process, other threads included. A filesystem without POSIX modes has none to set.
        if ($entry === false && $this->filePermissions !== null) {
            @chmod($path, $this->filePermissions & $opened['mode'] & 0777);
        }
        return [$stream, $opened];
    }

    /** @param array<int|string, int> $entry an lstat() result */
    private static function isRegularFile(array $entry): bool
    {
        return ($entry['mode'] & 0170000) === 0100000;
    }

    /**
     * @param array<int|string, int> $opened
     * @param array<int|string, int>|false $entry
     */
    private static function isSameFile(array $opened, array|false $entry): bool
    {
        return $entry !== false && $entry['dev'] === $opened['dev'] && $entry['ino'] === $opened['ino'];
    }

    /**
     * Whether the period that began at $began is over: its interval has elapsed, or its schedule crossed a calendar
     * boundary in the timezone of the clock, where a day lasts 23 or 25 hours when daylight saving time changes.
     */
    private function periodEnded(int $began, DateTimeImmutable $now): bool
    {
        $schedule = $this->rotation->schedule;
        if ($schedule !== null) {
            $start = $now->setTimestamp($began);
            return self::calendarPeriod($schedule, $start) !== self::calendarPeriod($schedule, $now);
        }
        $interval = $this->rotation->intervalSeconds;
        return $interval !== null && $now->getTimestamp() - $began >= $interval;
    }

    private static function calendarPeriod(Schedule $schedule, DateTimeImmutable $time): string
    {
        return $time->format(match ($schedule) {
            // The offset makes the hour that repeats when daylight saving time ends a period of its own.
            Schedule::Hourly => 'Y-m-d H O',
            Schedule::Daily => 'Y-m-d',
            Schedule::Weekly => 'o-W',
            Schedule::Monthly => 'Y-m',
        });
    }

    /**
     * @param resource $lock
     * @param array<int|string, int> $stat
     */
    private function periodStart($lock, array $stat, int $now): int
    {
        rewind($lock);
        // The state is one short JSON object; anything longer is not state and starts a new period.
        $contents = fread($lock, 4096);
        if ($contents === false) {
            throw new WriteException(sprintf('Unable to read rotation state for "%s".', $this->path));
        }
        $state = json_decode($contents, true);
        if (
            $stat['size'] > 0 && is_array($state)
            && ($state['dev'] ?? null) === $stat['dev'] && ($state['ino'] ?? null) === $stat['ino']
            && is_int($state['startedAt'] ?? null)
        ) {
            return $state['startedAt'];
        }

        // First adoption, a new active file, or interrupted state initialization starts a new period.
        $this->savePeriod($lock, $stat, $now);
        return $now;
    }

    /**
     * @param resource $lock
     * @param array<int|string, int> $stat
     */
    private function savePeriod($lock, array $stat, int $startedAt): void
    {
        $data = json_encode([
            'dev' => $stat['dev'],
            'ino' => $stat['ino'],
            'startedAt' => $startedAt,
        ], JSON_THROW_ON_ERROR);
        if (!rewind($lock) || !ftruncate($lock, 0)) {
            throw new WriteException(sprintf('Unable to reset rotation state for "%s".', $this->path));
        }
        StreamIo::writeAll($lock, $data, $this->path . '.lock');
    }

    /** @return list<Archive> the archives retention counts, the new one included */
    private function rotate(DateTimeImmutable $now): array
    {
        // Only retention reads the archives: it orders those of one microsecond by sequence, which must grow
        // past pruned gaps. Without retention, probing for a free name keeps every name unique.
        $archives = $this->rotation->maxFiles === null ? [] : $this->archives();
        $sequence = 0;
        foreach ($archives as $archive) {
            if ($archive['time'] == $now) {
                $sequence = max($sequence, $archive['sequence'] + 1);
            }
        }
        $stamp = $now->format(self::ARCHIVE_DATE_FORMAT);
        $target = $this->path . '.' . $stamp . '.' . $sequence;
        while (@lstat($target) !== false) {
            $target = $this->path . '.' . $stamp . '.' . ++$sequence;
        }

        if (!@rename($this->path, $target)) {
            throw new WriteException(sprintf('Unable to rotate "%s" to "%s".', $this->path, $target));
        }
        $archives[] = ['path' => $target, 'time' => $now, 'sequence' => $sequence];
        return $archives;
    }

    /** @param list<Archive> $archives */
    private function prune(array $archives, int $maxFiles): void
    {
        usort($archives, static fn(array $a, array $b): int =>
            ($a['time'] <=> $b['time']) ?: ($a['sequence'] <=> $b['sequence']));
        foreach (array_slice($archives, 0, max(0, count($archives) - $maxFiles)) as $archive) {
            if (!@unlink($archive['path'])) {
                throw new WriteException(sprintf('Unable to remove log archive "%s".', $archive['path']));
            }
        }
    }

    /** @return list<Archive> */
    private function archives(): array
    {
        $directory = dirname($this->path);
        $entries = @scandir($directory, SCANDIR_SORT_NONE);
        if ($entries === false) {
            throw new WriteException(sprintf('Unable to list log directory "%s".', $directory));
        }
        // The stamp is validated by parsing it back below, so ARCHIVE_DATE_FORMAT stays its only definition.
        $pattern = '~\A' . preg_quote(basename($this->path), '~') . '\.(.+)\.(0|[1-9]\d*)\z~';
        $archives = [];
        foreach ($entries as $name) {
            if (preg_match($pattern, $name, $matches) !== 1) {
                continue;
            }
            [, $stamp, $sequence] = $matches;
            $time = DateTimeImmutable::createFromFormat('!' . self::ARCHIVE_DATE_FORMAT, $stamp);
            if ($time === false || $time->format(self::ARCHIVE_DATE_FORMAT) !== $stamp) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            $entry = @lstat($path);
            if ($entry !== false && self::isRegularFile($entry)) {
                $archives[] = ['path' => $path, 'time' => $time, 'sequence' => (int) $sequence];
            }
        }
        return $archives;
    }
}
