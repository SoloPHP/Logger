# Log Rotation

`FileWriter` rotates the active file itself: no cron job, no `logrotate`, no background process. Rotation happens inside a write, under the same lock as the append, so any number of processes can share one log file.

```php
use Solo\Logger\Logger;
use Solo\Logger\Rotation\RotationOptions;
use Solo\Logger\Rotation\Schedule;

$logger = Logger::file(
    '/var/log/app/app.log',
    rotation: new RotationOptions(
        maxBytes: 10 * 1024 * 1024, // archive before the file would pass 10 MiB
        schedule: Schedule::Daily,  // and after every midnight
        maxFiles: 14,               // keep the 14 newest archives
    ),
);
```

## Options

| Option | Default | Effect |
|---|---|---|
| `maxBytes` | `null` | Rotate before a record would take the active file past this size |
| `intervalSeconds` | `null` | Rotate on the first write this many seconds after the active period began |
| `schedule` | `null` | Rotate on the first write in a new hour, day, week or month: a `Schedule` case |
| `maxFiles` | `null` | After a rotation, delete the oldest archives beyond this count |

Each limit is a positive integer, or `null` to disable it; without options nothing rotates. Size and time combine: whichever is reached first rotates the file, and either rotation starts a new period. `intervalSeconds` and `schedule` exclude each other: passing both throws `InvalidArgumentException`.

`maxFiles` needs a trigger. `new RotationOptions(maxFiles: 14)` alone throws `InvalidArgumentException`: retention runs after a rotation, so it would never apply.

## Size

The writer rotates **before** a record would take the file past `maxBytes`, counting the record and its `\n`:

```php
use Solo\Logger\Rotation\RotationOptions;
use Solo\Logger\Writer\FileWriter;

$writer = new FileWriter('/var/log/app/app.log', new RotationOptions(maxBytes: 8));

$writer->write('abc'); // app.log: "abc\n"       (4 bytes)
$writer->write('def'); // app.log: "abc\ndef\n"  (8 bytes, the limit)
$writer->write('ghi'); // app.log is archived; the new app.log: "ghi\n"
```

A record is never split, and an empty archive is never created: a record larger than `maxBytes` goes whole into an empty file, which stays over the limit until the next write rotates it.

## Time

Time rotation ends the active **period**, which starts when a writer first adopts the file and again with every rotation, by time or by size. Its start is stored in the `.lock` file, so every process and every writer instance agrees on it. Either way the file rotates on the first write past the end of the period: nothing runs while the application is idle.

### Schedule

`schedule` follows the calendar of the writer's clock:

| Case | Rotates on the first write in a new |
|---|---|
| `Schedule::Hourly` | hour |
| `Schedule::Daily` | day, after midnight |
| `Schedule::Weekly` | ISO week, which starts on Monday |
| `Schedule::Monthly` | month |

The clock is in UTC by default; `Logger::file(timezone: 'Europe/Kyiv')` rotates at Kyiv's midnight. A day lasts 23 or 25 hours when daylight saving time changes, and the hour that repeats when it ends is an hour of its own. A size rotation in the middle of the day starts a new period, but the next one still ends at midnight.

An archive is named after the moment of its rotation. With `Schedule::Daily`, the archive of 1 November is rotated by the first record of 2 November and reads `app.log.2026-11-02_00-00-03.123456+0000.0`.

### Interval

`intervalSeconds` counts from the start of the period, not from the last write, so steady traffic does not postpone a rotation. `86400` rotates a day after the period began, not at midnight: the timezone and daylight saving time do not change it.

## Retention

`maxFiles` counts archives, not the active file or the `.lock` file. Once a rotation and the write that caused it have both succeeded, the oldest archives beyond the limit are deleted. It limits a number of files, not their age or the space they take.

Only archives of this exact file are counted. Other channels (`worker.log`), files that merely look alike, symlinks and the archives of version 1 are left alone.

## Archive Names

```text
app.log
app.log.lock
app.log.2026-11-01_09-30-00.123456+0200.0
app.log.2026-11-01_09-30-00.123456+0200.1
```

An archive keeps the whole file name and adds the rotation time — with microseconds and the UTC offset — and a sequence number for rotations within the same microsecond. Retention orders archives by that instant, offsets included, then by sequence.

The name uses the timezone of the writer's clock, which `Logger::file(timezone: 'Europe/Kyiv')` also uses for the records. Point log collectors at `app.log` and `app.log.*`, excluding `app.log.lock`.

## Many Processes

Each write takes an exclusive `flock()` on `app.log.lock`, then reads the current size, rotates if needed, appends and prunes, all under that lock. The lock file keeps its name while the log is renamed, so a writer that opens the log after another process rotated it appends to the new file. This covers PHP-FPM workers, queue consumers and cron jobs alike, and several writer instances in one process.

The contract:

- Every writer of a path uses this package **with the same rotation options** — and, with a `schedule`, a clock in the same timezone, or each writer rotates at its own midnight.
- `app.log.lock` is persistent state, not a temporary file. Keep it while writers run; the application's user must be able to create and write it.
- No external tool — `logrotate`, `copytruncate` — rotates or truncates these files while writers run.

## Filesystem

- The destination is a **regular local file**: not a stream wrapper such as `php://stderr`, not a directory, not a symlink. A symlinked log or lock file is refused, so the writer cannot be tricked into appending to, or truncating, whatever the link points to. For streams, use [`StreamWriter`](/features/streams).
- A relative path is resolved against the working directory when the writer is created; a later `chdir()` does not move the log.
- Missing directories are created recursively, with mode `0755` minus the umask by default; `directoryPermissions` sets another.
- Files get the umask's default mode, usually `0644`: readable by every local user. `filePermissions` sets the mode of the files the writer creates — the log, and so its archives, and the lock file — minus the umask, as for directories:

  ```php
  $logger = Logger::file('/var/log/app/app.log', directoryPermissions: 0750, filePermissions: 0640);
  ```

  `new FileWriter($path, directoryPermissions: 0750, filePermissions: 0640)` takes the same modes.

  A file that already exists keeps its mode. A filesystem without POSIX modes ignores the setting. Both modes must leave the owner what the writer needs — `0700` on directories, `0600` on files — or the constructor throws.
- Each record is flushed to the operating system, not `fsync`ed to the disk.

## Adopting an Existing Log

A writer adopts an existing file: its records stay, and its first period starts with the first write. The same happens when the rotation state is missing, or belongs to a file that was replaced or emptied.

Upgrading from version 1 also needs the old writers stopped first: see [UPGRADE.md](https://github.com/SoloPHP/Logger/blob/main/UPGRADE.md#rotation-and-rollout).

## Failures

Every failure throws `Solo\Logger\Writer\WriteException`: creating the directory, locking, opening, writing, flushing, rotating, pruning. The lock and the files are released either way.

- A failed rotation deletes nothing and does not cost the record: it is appended to the active file, which stays over its limit, and then the write throws. The next write tries the rotation again, so the log keeps going while, say, the directory refuses renames.
- A failed write may leave part of the record in the file.
- Pruning runs after the record was appended: a retention error means the record **is** in the log.

So do not blindly retry a log call that threw. See [Fail-Safe Logging](/features/decorators#fail-safe-logging) for keeping such errors away from your requests.
