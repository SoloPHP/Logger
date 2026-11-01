# Writers

## WriterInterface

```php
namespace Solo\Logger\Writer;

interface WriterInterface
{
    /**
     * Write a formatted record without a trailing newline. The writer owns record framing.
     *
     * @throws WriteException when the destination fails
     */
    public function write(string $record): void;
}
```

See [Custom Writers](/features/extending#custom-writers).

---

## FileWriter

```php
final readonly class FileWriter implements WriterInterface
{
    public function __construct(
        string $path,
        RotationOptions $rotation = new RotationOptions(),
        Psr\Clock\ClockInterface $clock = new SystemClock(),
        int $directoryPermissions = 0755,
        ?int $filePermissions = null,
    );
}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$path` | `string` | The active log file; a relative path is resolved against the current working directory now |
| `$rotation` | `RotationOptions` | Size, time and retention limits; none by default |
| `$clock` | `ClockInterface` | The time for rotation periods and archive names |
| `$directoryPermissions` | `int` | The mode for directories it creates, minus the umask; it must include `0700` |
| `$filePermissions` | `?int` | The mode for files it creates — the log, and so its archives, and the lock file — minus the umask; it must include `0600`; `null` leaves it to the umask |

**Throws:**
- `InvalidArgumentException` — an empty path, a path with a NUL byte or a stream wrapper (`://`), a path ending in `/`, `.` or `..`, or a mode outside `0000`–`0777` or without the owner's bits: the writer must still read, write and enter what it creates
- `RuntimeException` — a relative path while the working directory cannot be determined

The constructor touches no file. Each `write()` appends the record and `\n` to the file, under an exclusive lock on the sibling `.lock` file, rotating and pruning when the options say so. The record is written as it is: a line break inside it, which no built-in formatter produces, splits it over several lines. See [Log Rotation](/features/rotation).

**`write()` throws** `WriteException` — the directory cannot be created; the lock or the log file cannot be opened, or is a symlink or not a regular file; locking, writing, flushing, rotating or pruning fails. A failed rotation or pruning throws after the record was appended. See [Failures](/features/rotation#failures).

---

## StreamWriter

```php
final class StreamWriter implements WriterInterface
{
    public function __construct(string $url = 'php://stderr');
}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$url` | `string` | A stream PHP can open for appending: `php://stderr`, `php://stdout`, a pipe, a device |

**Throws:** `InvalidArgumentException` — an empty URL, or one with a NUL byte.

The constructor touches nothing. The first `write()` opens the stream in mode `ab` and keeps it open; each record goes out with its `\n` in one write. Nothing is locked or rotated, and no directory is created; a missing file is created. See [Streams](/features/streams).

**`write()` throws** `WriteException` — the stream cannot be opened, written or flushed.

---

## RotationOptions

```php
namespace Solo\Logger\Rotation;

final readonly class RotationOptions
{
    public function __construct(
        public ?int $maxBytes = null,
        public ?int $intervalSeconds = null,
        public ?Schedule $schedule = null,
        public ?int $maxFiles = null,
    );
}
```

| Property | Description |
|----------|-------------|
| `$maxBytes` | Rotate before a record would take the active file past this size |
| `$intervalSeconds` | Rotate on the first write this many seconds after the active period began |
| `$schedule` | Rotate on the first write in a new hour, day, week or month of the writer's clock |
| `$maxFiles` | Keep this many archives, deleting the oldest after a rotation |

**Throws:** `InvalidArgumentException` — a limit that is not positive, both `$intervalSeconds` and `$schedule`, or `$maxFiles` without `$maxBytes`, `$intervalSeconds` or `$schedule`.

`null` disables a limit. Every writer of one file must use the same options. See [Log Rotation](/features/rotation#time).

---

## Schedule

```php
namespace Solo\Logger\Rotation;

enum Schedule
{
    case Hourly;
    case Daily;
    case Weekly;  // ISO weeks, which start on Monday
    case Monthly;
}
```

The calendar is the one of the writer's clock, in its timezone. A day lasts 23 or 25 hours when daylight saving time changes; the hour that repeats when it ends is an hour of its own.

---

## WriteException

```php
final class WriteException extends \RuntimeException
```

The destination failed: a record could not be written, rotated or retained. Catch it to survive an outage without hiding bugs — see [Fail-Safe Logging](/features/decorators#fail-safe-logging). Custom writers throw it too.

A `WriteException` does not mean nothing was written: a write can fail halfway, a failed rotation still appends the record, and a retention error comes after the record was appended.
