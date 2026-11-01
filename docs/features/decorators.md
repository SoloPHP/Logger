# Decorators

A decorator wraps a PSR-3 logger and adds one behaviour. Three ship with the package. Each takes any `Psr\Log\LoggerInterface`, so they combine with one another and with loggers from other libraries.

| Decorator | What it adds |
|---|---|
| [`FanOutLogger`](#several-destinations) | Sends every record to several loggers |
| [`FailSafeLogger`](#fail-safe-logging) | Reports output failures instead of throwing them |
| [`ContextLogger`](#shared-context) | Adds the same context to every record |

## Several Destinations

```php
use Psr\Log\LogLevel;
use Solo\Logger\Decorator\FanOutLogger;
use Solo\Logger\Formatter\JsonFormatter;
use Solo\Logger\Logger;

$logger = new FanOutLogger(
    Logger::file('/var/log/app/app.log', formatter: new JsonFormatter()),
    Logger::file('/var/log/app/error.log', minLevel: LogLevel::ERROR),
);
```

Each logger keeps its own destination, format and threshold. A failing logger does not keep the record from the others: every one is called, then the first failure is rethrown. A bug, such as an invalid level or a broken formatter, wins over an output failure, so a `FailSafeLogger` around the fan-out cannot hide it.

## Fail-Safe Logging

A failing destination — a full disk, a permission error — throws `WriteException` from the log call. To keep it from failing a request:

```php
use Solo\Logger\Decorator\FailSafeLogger;

$logger = new FailSafeLogger(Logger::file('/var/log/app/app.log'));
```

The failure goes to PHP's own error log through `error_log()`, as `Logging failed: Unable to write to "/var/log/app/app.log".`. Only `WriteException` is caught: an invalid level or a broken formatter is a bug, not an outage, and still throws. To report failures elsewhere, pass a closure:

```php
use Solo\Logger\Writer\WriteException;

$logger = new FailSafeLogger($inner, static function (WriteException $failure) use ($metrics): void {
    $metrics->increment('logging.failures');
});
```

A `WriteException` does not prove that nothing was written: see [Failures](/features/rotation#failures).

## Shared Context

```php
use Solo\Logger\Decorator\ContextLogger;

$logger = new ContextLogger($logger, ['request_id' => 'req-42']);

$logger->info('Order {id} shipped', ['id' => 7]);
// {"time":"2026-11-01T09:30:00.123456+00:00","level":"info","message":"Order 7 shipped","context":{"request_id":"req-42","id":7}}
```

The shared keys come first in every record. A key passed to the call wins over a shared one.

## Combining Them

```php
$logger = new ContextLogger(
    new FailSafeLogger(new FanOutLogger(
        Logger::file('/var/log/app/app.log'),
        Logger::stream(minLevel: LogLevel::ERROR, formatter: new JsonFormatter()),
    )),
    ['request_id' => $requestId],
);
```

The order matters. The context is added once, before the record is sent on. The fail-safe sits outside the fan-out, so a failing file still lets standard error have the record before the failure is reported. It reports only the first failure, though; to hear of every destination that fails, give each its own fail-safe:

```php
$logger = new FanOutLogger(
    new FailSafeLogger(Logger::file('/var/log/app/app.log')),
    new FailSafeLogger(Logger::stream(minLevel: LogLevel::ERROR, formatter: new JsonFormatter())),
);
```

## Checking a Level

`Logger` and the three decorators implement `Solo\Logger\LevelAwareLoggerInterface`, whose `isHandling()` tells whether a level would be written:

```php
use Solo\Logger\LevelAwareLoggerInterface;

if (!$logger instanceof LevelAwareLoggerInterface || $logger->isHandling(LogLevel::DEBUG)) {
    $logger->debug('Query plan', ['plan' => $database->explain($query)]);
}
```

A decorator asks the loggers it wraps; a fan-out handles a level when any of its loggers does. A wrapped logger from another library cannot tell, and counts as handling every level. An unknown level throws `Psr\Log\InvalidArgumentException` from every `isHandling()`, as from `Logger::log()`.

## Your Own Decorator

PSR-3's `AbstractLogger` turns the eight level methods into `log()`, so a decorator overrides one method:

```php
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class MemoryUsageLogger extends AbstractLogger
{
    public function __construct(private readonly LoggerInterface $inner)
    {
    }

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, $context + ['memory' => memory_get_usage()]);
    }
}
```

Unlike `ContextLogger`, which adds fixed values, this one computes its value for each record.
