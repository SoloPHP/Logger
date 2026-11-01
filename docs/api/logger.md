# Logger

The PSR-3 logger. Final; extends `Psr\Log\AbstractLogger` and implements [`LevelAwareLoggerInterface`](#levelawareloggerinterface), so it is a `Psr\Log\LoggerInterface`.

```php
use Solo\Logger\Logger;

$logger = Logger::file('/var/log/app/app.log');
```

## Constructor

```php
public function __construct(
    WriterInterface $writer,
    FormatterInterface $formatter = new LineFormatter(),
    string $minLevel = LogLevel::DEBUG,
    ClockInterface $clock = new SystemClock(),
    ?string $channel = null,
)
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$writer` | `WriterInterface` | Where formatted records go |
| `$formatter` | `FormatterInterface` | Turns a record into a line |
| `$minLevel` | `string` | The least severe level written, a `Psr\Log\LogLevel` constant |
| `$clock` | `Psr\Clock\ClockInterface` | The time source for the records |
| `$channel` | `?string` | The channel stamped on every record; none by default |

**Throws:**
- `Psr\Log\InvalidArgumentException` — `$minLevel` is not one of the eight PSR-3 levels
- `InvalidArgumentException` — a `$channel` that is empty, not valid UTF-8, or holds a control character

The writer, the formatter, the clock and the channel are fixed for the logger's lifetime: build another logger for another destination or format, or call [`withChannel()`](#withchannel). See [Extending](/features/extending).

---

## file()

```php
public static function file(
    string $path,
    string $minLevel = LogLevel::DEBUG,
    FormatterInterface $formatter = new LineFormatter(),
    RotationOptions $rotation = new RotationOptions(),
    string $timezone = 'UTC',
    int $directoryPermissions = 0755,
    ?int $filePermissions = null,
): self
```

A logger writing to a local file. The same as:

```php
$clock = new SystemClock(new \DateTimeZone($timezone));
$writer = new FileWriter($path, $rotation, $clock, $directoryPermissions, $filePermissions);

$logger = new Logger($writer, $formatter, $minLevel, $clock);
```

One clock stamps the records, names the archives and draws the calendar of a [schedule](/features/rotation#schedule), so all three use `$timezone`. The modes are those of [`FileWriter`](/api/writers#filewriter).

**Throws:**
- `InvalidArgumentException` — an empty path, a stream wrapper URL, a path ending in `/`, `.` or `..`, or a mode the writer could not work with
- `Psr\Log\InvalidArgumentException` — `$minLevel` is not a PSR-3 level
- `DateInvalidTimeZoneException` — an unknown `$timezone`

**Example:**

```php
use Psr\Log\LogLevel;
use Solo\Logger\Formatter\JsonFormatter;
use Solo\Logger\Rotation\RotationOptions;
use Solo\Logger\Rotation\Schedule;

$logger = Logger::file(
    path: '/var/log/app/app.log',
    minLevel: LogLevel::INFO,
    formatter: new JsonFormatter(),
    rotation: new RotationOptions(maxBytes: 10 * 1024 * 1024, schedule: Schedule::Daily, maxFiles: 14),
    timezone: 'Europe/Kyiv',
    filePermissions: 0640,
);
```

---

## stream()

```php
public static function stream(
    string $url = 'php://stderr',
    string $minLevel = LogLevel::DEBUG,
    FormatterInterface $formatter = new LineFormatter(),
    string $timezone = 'UTC',
): self
```

A logger writing to a stream: standard error by default. The same as:

```php
$logger = new Logger(new StreamWriter($url), $formatter, $minLevel, new SystemClock(new \DateTimeZone($timezone)));
```

**Throws:**
- `InvalidArgumentException` — an empty URL, or one with a NUL byte
- `Psr\Log\InvalidArgumentException` — `$minLevel` is not a PSR-3 level
- `DateInvalidTimeZoneException` — an unknown `$timezone`

See [Streams](/features/streams).

---

## withChannel()

```php
public function withChannel(string $channel): self
```

A new logger with the same writer, formatter, clock and threshold, stamping `$channel` on its records. The two thresholds change independently afterwards.

**Throws:** `InvalidArgumentException` — a `$channel` that is empty, not valid UTF-8, or holds a control character.

```php
$payments = $logger->withChannel('payments');
$payments->info('Charged');
// [2026-11-01T09:30:00.123456+00:00] payments.INFO: Charged
```

---

## setMinLevel()

```php
public function setMinLevel(string $level): void
```

Change the threshold — the one setting an existing logger can change.

**Throws:** `Psr\Log\InvalidArgumentException` — not a PSR-3 level; the previous threshold stays.

---

## isHandling()

```php
public function isHandling(string $level): bool
```

Whether a record of `$level` would be written: `true` for the threshold and the levels more severe than it.

**Throws:** `Psr\Log\InvalidArgumentException` — not a PSR-3 level.

```php
if ($logger->isHandling(LogLevel::DEBUG)) {
    $logger->debug('Query plan', ['plan' => $database->explain($query)]);
}
```

---

## log()

```php
public function log($level, string|\Stringable $message, array $context = []): void
```

Write one record, and the same through `emergency()`, `alert()`, `critical()`, `error()`, `warning()`, `notice()`, `info()` and `debug()`.

1. The level is checked: anything but the eight PSR-3 levels throws `Psr\Log\InvalidArgumentException`, also below the threshold.
2. A level below the threshold returns here: the message is not converted, the clock not read, nothing formatted or written.
3. The message is converted to a string, the clock read, and a `LogRecord` built with the logger's channel.
4. The formatter formats the record, then the writer writes it.

**Throws:**
- `Psr\Log\InvalidArgumentException` — an unknown level
- `Solo\Logger\Writer\WriteException` — the writer could not write, rotate or prune
- Whatever a custom formatter or writer throws, or the message's `__toString()`

A failed call is never retried or dropped silently: the exception reaches the caller. See [Fail-Safe Logging](/features/decorators#fail-safe-logging).

---

## LevelAwareLoggerInterface

```php
namespace Solo\Logger;

interface LevelAwareLoggerInterface extends Psr\Log\LoggerInterface
{
    /** @throws \Psr\Log\InvalidArgumentException when the level is not one of the eight PSR-3 levels */
    public function isHandling(string $level): bool;
}
```

A PSR-3 logger that tells in advance whether it would write a level. `Logger` and the [decorators](/api/decorators) implement it, and every one of them throws for an unknown level.

---

## LogRecord

```php
final readonly class LogRecord
{
    public function __construct(
        public string $level,
        public string $message,
        public array $context,
        public \DateTimeImmutable $time,
        public ?string $channel = null,
    ) {
    }
}
```

What a formatter receives. `message` is already a string, its placeholders not yet replaced; `context` is the array as it was passed, not normalized; `channel` is the logger's, or `null`. The properties are readonly, but objects inside the context are not frozen.

---

## SystemClock

```php
final readonly class SystemClock implements Psr\Clock\ClockInterface
{
    public function __construct(\DateTimeZone $timezone = new \DateTimeZone('UTC'));

    public function now(): \DateTimeImmutable;
}
```

The current time, with microseconds, in the given timezone — whatever `date.timezone` says.
