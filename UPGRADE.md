# Upgrading from 1.x to 2.0

2.0 splits the logger into a writer, a formatter and a clock, and makes it strict: configuration is fixed and checked when the logger is built, unknown levels throw, and output failures throw instead of disappearing. Rotation is coordinated across processes under one lock, and every format writes one line with a fixed shape.

## Checklist

| Look for | Do |
|---|---|
| PHP 8.1 / 8.2 | Upgrade to PHP 8.3+; the package now also depends on `psr/clock` |
| `new Logger($path, …)` | `Logger::file($path, …)` with named arguments ([Construction](#construction)) |
| `new Logger('')` to disable logging | `new Psr\Log\NullLogger()` |
| `setLogFile()`, `setTimezone()`, `setFormatter()` | Build a new logger with those settings |
| `setMaxFileSize()`, `setMaxFiles()`, `setRotationInterval()`, `setRotationStrategy()` | `RotationOptions` ([Configuration](#configuration)) |
| `0` to disable a size or count limit | `null`; zero and negative limits throw |
| `maxFiles` without a size or time limit | Add `maxBytes` or `intervalSeconds`, or drop it: it never pruned anything, and now throws |
| Custom levels (`'fatal'`, `'INFO'`) | Map them to the eight PSR-3 levels: anything else throws `Psr\Log\InvalidArgumentException` |
| `class … extends Logger` | `Logger` is final: wrap it in a decorator, or write a formatter or a writer |
| Your own `FormatterInterface` | Implement `format(LogRecord $record): string` ([Custom formatters](#custom-formatters)) |
| `new JsonFormatter(jsonFlags: …)` | Remove the argument: the flags are fixed so that every record stays one valid JSON line |
| JSON queries on context fields: `id`, `user_id` | Read them under `context`: `context.id` ([Output formats](#output-formats)) |
| Logfmt parsers | Every value is quoted now; context keys read `context.<percent-encoded key>` |
| Consumers of the line format | The context JSON is appended to each line; control characters are escaped |
| Parsers of the record time | Times carry microseconds, `2026-11-01T09:30:00.123456+00:00`; `dateFormat: DATE_ATOM` restores whole seconds |
| JSON or logfmt consumers that group by `message` | The message has its placeholders replaced now: group by a context field ([Output formats](#output-formats)) |
| `{exception}` in a message | It reads `Class: message` instead of the whole exception with its trace, which stays in the context |
| Collector patterns for `app_2025-08-03_14-30-15.log` | `app.log.*`, excluding `app.log.lock` ([Rotation and rollout](#rotation-and-rollout)) |
| 1.x writers still running against the same file | Stop them before the first 2.0 write ([Rotation and rollout](#rotation-and-rollout)) |
| Records stamped in `date.timezone` | UTC by default; `Logger::file(timezone: …)` for another zone |
| `catch (\RuntimeException)` around log calls | Keeps working; `catch (WriteException)` catches output failures only |
| Your own fail-safe wrapper, fan-out or writer for `php://stderr` | `FailSafeLogger`, `FanOutLogger`, `StreamWriter` or `Logger::stream()` ([Failure handling](#failure-handling)) |

## Construction

```php
// 1.x
$logger = new Logger('app.log', 'UTC', 1024 * 1024, 10, 'both', 3600, new JsonFormatter());
$logger->setMinLevel(LogLevel::WARNING);

// 2.0
use Solo\Logger\Rotation\RotationOptions;

$logger = Logger::file(
    path: 'app.log',
    minLevel: LogLevel::WARNING,
    formatter: new JsonFormatter(),
    rotation: new RotationOptions(maxBytes: 1024 * 1024, intervalSeconds: 3600, maxFiles: 10),
    timezone: 'UTC',
);
```

`Logger::file()` covers the file-backed case, and `Logger::stream()` standard error. The constructor itself now takes the parts: `new Logger(WriterInterface $writer, FormatterInterface $formatter, string $minLevel, ClockInterface $clock, ?string $channel)`. `Logger` is final and extends `Psr\Log\AbstractLogger`; see [Extending](https://solophp.github.io/Logger/features/extending) for decorators, writers and formatters.

## Configuration

| 1.x | 2.0 |
|---|---|
| `new Logger('')` | `new Psr\Log\NullLogger()` |
| `setLogFile()`, `setTimezone()`, `setFormatter()` | A new logger with the desired settings |
| `setMaxFileSize()` | `RotationOptions(maxBytes: …)` |
| `setRotationInterval()` | `RotationOptions(intervalSeconds: …)`, or `schedule: Schedule::Daily` to rotate at midnight |
| `setMaxFiles()` | `RotationOptions(maxFiles: …)` |
| `setRotationStrategy('size')` | Set only `maxBytes` |
| `setRotationStrategy('time')` | Set only `intervalSeconds` or `schedule` |
| `setRotationStrategy('both')` | Set both |
| A limit of `0` or less | `null` disables a limit; non-positive integers throw |
| `setMinLevel()` | Unchanged; also available as `minLevel` when building the logger |

`LoggerConfiguration`, `FileSystemManager`, `LogRotator` and `ContextInterpolator` are gone. `FileWriter` owns the file, its rotation and its retention; interpolation is part of `LineFormatter`.

The default timezone is UTC instead of `date.timezone`, and the factory's `timezone` now applies to the records as well as to the archive names.

## Rotation and rollout

- **Time** rotation counts from the start of the active period, stored in the `.lock` file, not from the file's last modification: steady traffic no longer postpones it. An existing log starts its first period with the first 2.0 write. New in 2.0, `schedule: Schedule::Daily` rotates on the first write after midnight in the logger's timezone, and `Hourly`, `Weekly` and `Monthly` follow the calendar the same way.
- **Size** rotation now counts the incoming record: the file is archived before it would pass `maxBytes`. A single larger record is written whole and never split.
- **Retention** runs after a successful rotation and write, and counts only 2.0 archives of the same file.
- **Archives** are named `app.log.YYYY-MM-DD_HH-MM-SS.uuuuuu+HHMM.N` instead of `app_YYYY-MM-DD_HH-MM-SS.log`. Update the patterns of log collectors. 1.x archives are never touched: move or delete them yourself.

The sibling `app.log.lock` is persistent coordination state, not a temporary file: keep it while writers run, and let the application's user create and write it. 1.x writers do not take that lock, so **stop every 1.x process writing a log before 2.0 writes to it**, and give all 2.0 writers of a file the same `RotationOptions`. Do not run `logrotate` on these files while writers run.

`FileWriter` writes regular local files only. A symlinked log or lock file is refused: point the logger at the real path. For stream wrappers such as `php://stderr`, use [`StreamWriter`](https://solophp.github.io/Logger/features/streams) or `Logger::stream()`. A relative path is resolved when the writer is created and no longer follows a later `chdir()`.

## Output formats

- **JSON:** context lives under `context` and no longer merges into the top level: query `context.id`, not `id`. `context` is always an object. `message` has its placeholders replaced, as in the line format: `User 42 signed in`, no longer `User {id} signed in`. Invalid UTF-8 no longer replaces the whole context with an error field: only the broken characters are substituted.
- **Logfmt:** context keys are prefixed with `context.` and percent-encoded (`rawurldecode()` the rest of the key). Every value is quoted with JSON escaping; a value that is not a string holds JSON text. `message` has its placeholders replaced. Exceptions are written in full, no longer as their message only.
- **Line:** the default template is `[{time}] {channel}{level}: {message}{context}`, so the normalized context — exceptions included — is appended as JSON. Leave `{context}` out of the template to opt out. Control characters in the time, level and message are escaped C-style (`\n`, `\033`), invalid UTF-8 becomes U+FFFD, and a template with a line break throws.
- **Placeholders**, in every format, spell booleans and `null` as `true`, `false`, `null` instead of `'1'` and `''`. `{exception}` reads `Class: message` instead of the exception's whole string with its stack trace; the context holds the trace.
- **All formats:** times carry microseconds, `Y-m-d\TH:i:s.uP` instead of `DATE_ATOM`; pass `dateFormat` to a formatter for another format. The context goes through one bounded normalizer: depth 8, 1,000 entries, 16 KiB per string by default, and the message is cut at the same length. Exceptions now include their stack frames, as `file:line Class->method()` strings without arguments, and list their previous exceptions under `previous`, the root cause last. Enums are written as their value or name. Other objects — `JsonSerializable` ones included — become a marker such as `[object:App\User]`: pass arrays for the fields you want. Writers end records with `\n` on every platform, not `PHP_EOL`.

## Custom formatters

```php
// 1.x
public function format(string $level, string|\Stringable $message, array $context, \DateTimeInterface $time): string

// 2.0
public function format(LogRecord $record): string
```

The readonly `LogRecord` has `level`, `message` (already a string), `context` and `time` (a `DateTimeImmutable`). Return a string without a trailing newline, and run the context through `ContextNormalizer` before encoding it; `ContextNormalizer::encode()` writes JSON the way the built-in formatters do.

## Failure handling

Rotation and retention failures used to be ignored silently; now every output failure throws `Solo\Logger\Writer\WriteException`, which extends `RuntimeException`. An exception does not prove that nothing was written: a write can fail halfway, a failed rotation still appends the record to the active file, and a retention error comes after the record was appended. Do not retry log calls blindly. To keep a failing destination from breaking requests, wrap the logger in `FailSafeLogger`, which reports output failures to `error_log()` — see [Fail-Safe Logging](https://solophp.github.io/Logger/features/decorators#fail-safe-logging).
