# Solo PSR-3 Logger 

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/logger.svg)](https://packagist.org/packages/solophp/logger)
[![License](https://img.shields.io/packagist/l/solophp/logger.svg)](https://github.com/solophp/logger/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/logger.svg)](https://packagist.org/packages/solophp/logger)
[![Test Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen.svg)](#development)

A lightweight PSR-3 compliant logger with timezone-aware log rotation, pluggable formatters (line / JSON / logfmt), context interpolation and safe, lock-based file writes.

## Installation

Install via composer:

```bash
composer require solophp/logger
```

## Usage

Basic usage:

```php
use Psr\Log\LogLevel;
use Solo\Logger\Logger;

// Initialize logger
$logger = new Logger('/path/to/log/file.log');

// Log messages
$logger->info('Application started');
$logger->error('Failed to charge user {user_id}', ['user_id' => 42, 'error_code' => 500]);

// Only WARNING and above will be written
$logger->setMinLevel(LogLevel::WARNING);
```

With custom timezone for rotation file timestamps:

```php
$logger = new Logger('/path/to/log/file.log', 'America/New_York');
```

Change log file or rotation timezone at runtime:

```php
$logger->setLogFile('/path/to/another/file.log');
$logger->setTimezone('Asia/Tokyo');
```

## Features

- PSR-3 `LoggerInterface` compliance (`psr/log` ^3.0)
- Pluggable formatters: **Line**, **JSON (NDJSON)**, **Logfmt** — or your own via `FormatterInterface`
- Context interpolation for PSR-3 `{placeholder}` tokens
- Log rotation with **size**, **time**, or **combined** strategies
- Timezone-aware rotation timestamps
- Retention via `maxFiles` — oldest rotated files are auto-pruned
- Minimum level filtering via `setMinLevel()`
- Automatic log directory creation
- Safe append-with-lock writes (`LOCK_EX`)
- Throws `RuntimeException` on write/directory-create failures instead of silent drop
- Modular architecture with a clear separation of concerns

## Requirements

- PHP 8.1 or higher
- psr/log package

## Log Levels

The logger supports all PSR-3 log levels:
- emergency
- alert
- critical
- error
- warning
- notice
- info
- debug

## Log Rotation

The logger supports automatic log rotation with multiple strategies to prevent disk overflow and simplify log management.

### Rotation Strategies

#### Size-based rotation
Rotates logs when the file reaches a specified size limit:

```php
// Rotate when file reaches 1MB, keep maximum 5 files
$logger = new Logger('app.log', '', 1024 * 1024, 5, 'size');
```

#### Time-based rotation
Rotates logs at specified time intervals:

```php
// Rotate every 6 hours, keep maximum 10 files
$logger = new Logger('app.log', '', 0, 10, 'time', 6 * 3600);
```

#### Combined rotation
Rotates logs when either size OR time condition is met:

```php
// Rotate when file reaches 2MB OR every 12 hours, keep maximum 7 files
$logger = new Logger('app.log', '', 2 * 1024 * 1024, 7, 'both', 12 * 3600);
```

### Runtime Configuration

You can change rotation settings at runtime:

```php
$logger->setMaxFileSize(1024 * 1024); // 1MB
$logger->setMaxFiles(10);
$logger->setRotationStrategy('both');
$logger->setRotationInterval(3600); // 1 hour
// Record WARNING and higher only
$logger->setMinLevel(LogLevel::WARNING);
```

### File Naming

When rotation occurs, files are created with timestamps:

```
original_name_YYYY-MM-DD_HH-MM-SS.ext
```

Examples:
- `app_2025-08-03_14-30-15.log`
- `error_2025-08-03_09-45-30.log`

### Best Practices

- **Size limits**: Set reasonable limits (1-10MB) to prevent disk overflow
- **File count**: Limit the number of files to save space
- **Time intervals**: Use standard intervals (1 hour, 6 hours, 1 day)
- **Monitoring**: Regularly check log directory size
- **Archiving**: Consider archiving old logs for long-term storage

### Examples

#### Daily rotation with 30-day retention
```php
$logger = new Logger('logs/daily.log', '', 0, 30, 'time', 86400);
```

#### Size-based with 50MB total limit
```php
$logger = new Logger('logs/large.log', '', 5 * 1024 * 1024, 10, 'size');
```

#### Combined strategy for high-traffic applications
```php
$logger = new Logger(
    'logs/combined.log', 
    '', 
    2 * 1024 * 1024,  // 2MB limit
    20,                 // 20 files max
    'both',             // both size and time
    6 * 3600           // 6 hours
);
```

## Formatters

Every log record is rendered by a `FormatterInterface` implementation. The default is `LineFormatter`; pass a different one via the constructor or `setFormatter()` at runtime.

```php
use Solo\Logger\Formatter\JsonFormatter;
use Solo\Logger\Formatter\LineFormatter;
use Solo\Logger\Formatter\LogfmtFormatter;
use Solo\Logger\Logger;

// Via constructor (last argument)
$logger = new Logger('app.log', '', 0, 0, 'size', 86400, new JsonFormatter());

// Or swap at runtime
$logger->setFormatter(new LogfmtFormatter());
```

### LineFormatter (default)

Plain text with a configurable template and PSR-3 `{placeholder}` interpolation.

```text
[2026-04-19T10:15:30+00:00] INFO: User 42 logged in
```

```php
// Defaults: template = "[{time}] {level}: {message}", date format = DateTimeInterface::ATOM
$formatter = new LineFormatter(
    template: '{time} [{level}] {message}',
    dateFormat: 'Y-m-d H:i:s',
);
```

Template tokens: `{time}`, `{level}`, `{message}`. Context keys referenced inside the message (e.g. `"User {user_id}"`) are resolved against the `$context` array.

### JsonFormatter

Single-line JSON (NDJSON) — fits ELK / Loki / Graylog / Datadog pipelines. Context keys are merged into the top-level record; reserved keys (`time`, `level`, `message`) are namespaced under `context` to avoid collisions. `\Throwable`, `\DateTimeInterface` and `\Stringable` objects are normalised.

```json
{"time":"2026-04-19T10:15:30+00:00","level":"info","message":"Job queued","id":3}
```

### LogfmtFormatter

Heroku/Splunk-style `key=value` pairs. Values with whitespace or quotes are escaped.

```text
time=2026-04-19T10:15:30+00:00 level=info message="Job queued" id=3
```

### Writing a custom formatter

Implement `Solo\Logger\Formatter\FormatterInterface` — a single `format()` method. The returned string MUST NOT include a trailing newline (the Logger appends one).

```php
use Solo\Logger\Formatter\FormatterInterface;

final class MyFormatter implements FormatterInterface
{
    public function format(string $level, string|\Stringable $message, array $context, \DateTimeInterface $time): string
    {
        return sprintf('%s %s %s', $time->format('c'), strtoupper($level), $message);
    }
}
```

## Error Handling

If writing a log record fails (unwritable path, full disk, unable to create directory), the logger throws `\RuntimeException` rather than silently dropping the record. Wrap calls in a `try/catch` if your application must tolerate log-sink failures.

## Development

```bash
# Install dependencies
composer install

# Run the full suite: PSR-12 + PHPStan L8 + PHPUnit
composer check

# Individual commands
composer test          # PHPUnit
composer test-coverage # PHPUnit + HTML coverage report
composer analyze       # PHPStan
composer cs            # PSR-12 style check
composer cs-fix        # PSR-12 auto-fix
```

## License

MIT License. See LICENSE file for details.