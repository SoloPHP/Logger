# Installation

## Requirements

- PHP 8.3 or higher
- Composer
- For file output: a local filesystem that supports `flock()` and renaming a file within its directory

## Composer

Install the package via Composer:

```bash
composer require solophp/logger
```

## Dependencies

```json
{
    "require": {
        "php": "^8.3",
        "psr/log": "^3.0",
        "psr/clock": "^1.0"
    }
}
```

- **psr/log** — the `LoggerInterface` the logger implements; the package provides `psr/log-implementation` 3.0
- **psr/clock** — the PSR-20 `ClockInterface` the logger and the file writer read the time from

No PHP extension beyond the default build is needed.

## Basic Setup

```php
use Psr\Log\LogLevel;
use Solo\Logger\Logger;
use Solo\Logger\Rotation\RotationOptions;

$logger = Logger::file(
    path: '/var/log/app/app.log',
    minLevel: LogLevel::INFO,
    rotation: new RotationOptions(maxBytes: 10 * 1024 * 1024, maxFiles: 14),
);

$logger->info('Application started');
```

`Logger::file()` builds the common setup: a [`FileWriter`](/api/writers#filewriter) for the path, the formatter you pass — [`LineFormatter`](/features/formatters#lineformatter) by default — and a clock in the given timezone, UTC by default. The directory is created on the first write, not before.

In a container, or for a worker whose standard error is collected, write there instead:

```php
use Solo\Logger\Formatter\JsonFormatter;

$logger = Logger::stream(minLevel: LogLevel::INFO, formatter: new JsonFormatter());
```

See [Streams](/features/streams).

## As a PSR-3 Service

The logger implements `Psr\Log\LoggerInterface`, so any library that accepts a PSR-3 logger takes it as is. Build it once — in a factory or your container — and share it:

```php
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Solo\Logger\Formatter\JsonFormatter;
use Solo\Logger\Logger;
use Solo\Logger\Rotation\RotationOptions;
use Solo\Logger\Rotation\Schedule;

final class LoggerFactory
{
    public static function create(string $logDirectory, bool $debug): LoggerInterface
    {
        return Logger::file(
            path: $logDirectory . '/app.log',
            minLevel: $debug ? LogLevel::DEBUG : LogLevel::INFO,
            formatter: new JsonFormatter(),
            rotation: new RotationOptions(schedule: Schedule::Daily, maxFiles: 30),
            filePermissions: 0640,
        );
    }
}
```

A logger keeps no state between records, and every `FileWriter` coordinates with the other processes that write the same file, so one instance per application is enough — also under PHP-FPM, queue workers and cron jobs running at the same time.

## Disabling Logging

A `Logger` always has a destination. Where no output is wanted, inject PSR-3's `Psr\Log\NullLogger` instead.

## Next Steps

- [Quick Start](/guide/quick-start) — Levels, placeholders, exceptions, channels and formats
- [Log Rotation](/features/rotation) — Size and time limits, retention, many processes
- [Decorators](/features/decorators) — Several destinations, fail-safe logging, shared context
