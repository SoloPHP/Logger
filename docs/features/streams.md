# Streams

Containers, systemd and supervisord collect what a process writes to its standard error. `StreamWriter` writes there, or to any other stream PHP can open for appending: `php://stdout`, a named pipe, a device.

```php
use Solo\Logger\Formatter\JsonFormatter;
use Solo\Logger\Logger;

$logger = Logger::stream(formatter: new JsonFormatter());

$logger->info('Worker started', ['queue' => 'mail']);
// stderr: {"time":"2026-11-01T09:30:00.123456+00:00","level":"info","message":"Worker started","context":{"queue":"mail"}}
```

`Logger::stream()` takes the stream — `php://stderr` by default — the minimum level, the formatter and the timezone, as `Logger::file()` takes a path. Built by hand:

```php
use Solo\Logger\Writer\StreamWriter;

$logger = new Logger(new StreamWriter('php://stdout'), new JsonFormatter());
```

## How It Writes

- The stream is opened on the first write and kept open; the constructor touches nothing.
- Each record goes out with its `\n` in one write. A pipe takes a write of up to `PIPE_BUF` bytes — 4 KiB on Linux — in one piece, so shorter records from processes that share a pipe never interleave.
- Nothing is locked or rotated, and no directory is created; a missing file is created in mode `ab` like any `fopen()`. For regular files, use [`FileWriter`](/features/rotation), which locks, rotates and creates directories.
- A stream that cannot be opened or written throws `WriteException`, as a file does.

## Containers

Write JSON to standard error and let the runtime collect it:

```php
$logger = Logger::stream(minLevel: LogLevel::INFO, formatter: new JsonFormatter());
```

Docker and Kubernetes split a line longer than 16 KiB into parts, which the collector has to join back. To keep records under that size, tighten the normalizer's [limits](/features/context#limits):

```php
use Solo\Logger\Context\ContextNormalizer;

$formatter = new JsonFormatter(normalizer: new ContextNormalizer(maxItems: 100, maxStringLength: 1024));
```

## PHP-FPM

An FPM worker's standard error reaches the FPM log only with `catch_workers_output = yes` in the pool. Two more settings decide what the lines look like:

- `decorate_workers_output = no` keeps FPM from prefixing each line with the pool and the worker, which breaks JSON lines.
- `log_limit`, 1024 bytes by default, is the longest line FPM writes in one piece; it splits longer ones. Raise it above your largest record.

## A File and Standard Error

```php
use Psr\Log\LogLevel;
use Solo\Logger\Decorator\FanOutLogger;

$logger = new FanOutLogger(
    Logger::file('/var/log/app/app.log'),
    Logger::stream(minLevel: LogLevel::ERROR, formatter: new JsonFormatter()),
);
```

See [Decorators](/features/decorators#several-destinations).
