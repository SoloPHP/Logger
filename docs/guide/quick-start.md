# Quick Start

## Logging

```php
use Solo\Logger\Logger;

$logger = Logger::file('/var/log/app/app.log');

$logger->info('Application started');
$logger->warning('Disk usage at {percent}%', ['percent' => 91]);
$logger->error('Payment failed', ['order_id' => 1001]);
```

```text
[2026-11-01T09:30:00.123456+00:00] INFO: Application started
[2026-11-01T09:30:00.123456+00:00] WARNING: Disk usage at 91% {"percent":91}
[2026-11-01T09:30:00.123456+00:00] ERROR: Payment failed {"order_id":1001}
```

Every PSR-3 method is available: `emergency()`, `alert()`, `critical()`, `error()`, `warning()`, `notice()`, `info()` and `debug()`, plus `log($level, $message, $context)` with a `Psr\Log\LogLevel` constant. Times are written in RFC 3339 with microseconds, UTC by default.

## Levels

From the most to the least severe:

| Level | Constant | For |
|---|---|---|
| `emergency` | `LogLevel::EMERGENCY` | The system is unusable |
| `alert` | `LogLevel::ALERT` | Someone must act now: the site is down, the database unreachable |
| `critical` | `LogLevel::CRITICAL` | A component is unavailable, an exception nobody expected |
| `error` | `LogLevel::ERROR` | Runtime errors that need monitoring, not immediate action |
| `warning` | `LogLevel::WARNING` | Exceptional but not errors: a deprecated API, a poor use of one |
| `notice` | `LogLevel::NOTICE` | Normal but significant events |
| `info` | `LogLevel::INFO` | Interesting events: a user signs in, a job runs |
| `debug` | `LogLevel::DEBUG` | Detailed debug information |

Any other level — a custom name, `'INFO'` in capitals, a non-string — throws `Psr\Log\InvalidArgumentException`:

```php
$logger->log('fatal', 'Disk full');
// Psr\Log\InvalidArgumentException: Unknown log level "fatal";
// expected one of: emergency, alert, critical, error, warning, notice, info, debug.
```

## Minimum Level

```php
use Psr\Log\LogLevel;

$logger = Logger::file('/var/log/app/app.log', minLevel: LogLevel::WARNING);

$logger->info('Skipped');     // below the threshold
$logger->error('Written');

$logger->setMinLevel(LogLevel::DEBUG);
```

A record below the threshold costs a level comparison and nothing else: the message is not converted to a string, the clock is not read, the context is not formatted and no file is touched. The threshold is the only setting an existing logger can change.

Context you have to compute is another matter: it is built before the call. `isHandling()` tells whether a level would be written, so you can skip that work:

```php
if ($logger->isHandling(LogLevel::DEBUG)) {
    $logger->debug('Query plan', ['plan' => $database->explain($query)]);
}
```

## Placeholders

Every formatter replaces PSR-3 placeholders — `{name}` — with context values:

```php
$logger->info('User {user} bought {count} items', ['user' => 'ann', 'count' => 3]);
// [2026-11-01T09:30:00.123456+00:00] INFO: User ann bought 3 items {"user":"ann","count":3}
```

Anything that becomes text or a number is interpolated: strings, numbers, booleans, `null`, enums, dates, `Stringable` objects. An exception reads as its class and message. Arrays are not interpolated. All values also stay in the context, so nothing is lost when a placeholder is missing or misspelled, and queries in JSON or logfmt read the fields there.

## Exceptions

Pass an exception under the `exception` key, as PSR-3 recommends:

```php
try {
    $gateway->charge($order);
} catch (PaymentException $e) {
    $logger->error('Payment failed: {exception}', ['order_id' => $order->id, 'exception' => $e]);
}
```

```text
[2026-11-01T09:30:00.123456+00:00] ERROR: Payment failed: App\Billing\PaymentException: Card declined {"order_id":1001,"exception":{"class":"App\\Billing\\PaymentException","message":"Card declined",…}}
```

In the message, `{exception}` reads `Class: message`. The context holds the whole description: class, message, code, file and line, the stack frames and the previous exceptions, with or without a placeholder. A frame names the call and where it was made; its arguments are never written. See [Context](/features/context#exceptions).

## Channels

A channel names the part of the application a record comes from. `withChannel()` returns a logger with the same output and threshold under another name:

```php
$logger = Logger::file('/var/log/app/app.log');
$payments = $logger->withChannel('payments');

$payments->info('Charged {amount} cents', ['amount' => 990]);
// [2026-11-01T09:30:00.123456+00:00] payments.INFO: Charged 990 cents {"amount":990}
```

JSON and logfmt write it as a `channel` field. A logger without a channel writes none.

## Choosing a Format

```php
use Solo\Logger\Formatter\JsonFormatter;

$logger = Logger::file('/var/log/app/app.log', formatter: new JsonFormatter());
```

| Formatter | `info('User {id} signed in', ['id' => 42])` |
|---|---|
| `LineFormatter` (default) | `[2026-11-01T09:30:00.123456+00:00] INFO: User 42 signed in {"id":42}` |
| `JsonFormatter` | `{"time":"2026-11-01T09:30:00.123456+00:00","level":"info","message":"User 42 signed in","context":{"id":42}}` |
| `LogfmtFormatter` | `time="2026-11-01T09:30:00.123456+00:00" level="info" message="User 42 signed in" context.id="42"` |

Each record is a single line; the writer ends it with `\n`. See [Formatters](/features/formatters).

## Rotating Files

```php
use Solo\Logger\Rotation\RotationOptions;
use Solo\Logger\Rotation\Schedule;

$logger = Logger::file(
    '/var/log/app/app.log',
    rotation: new RotationOptions(maxBytes: 10 * 1024 * 1024, schedule: Schedule::Daily, maxFiles: 14),
);
```

The file is archived before it would exceed 10 MiB, and after every midnight UTC, and the 14 newest archives are kept. `intervalSeconds` rotates a fixed time after the period began instead. See [Log Rotation](/features/rotation).

## Standard Error

```php
$logger = Logger::stream(formatter: new JsonFormatter());
```

`Logger::stream()` writes to `php://stderr`, or to the stream you name, for containers and supervised workers. See [Streams](/features/streams).

## When Writing Fails

A record that cannot be written — the directory cannot be created, the disk is full — throws `Solo\Logger\Writer\WriteException`, a `RuntimeException`. The logger never drops a record silently and never retries one. To keep a broken destination from failing requests, wrap the logger:

```php
use Solo\Logger\FailSafeLogger;

$logger = new FailSafeLogger(Logger::file('/var/log/app/app.log'));
```

Output failures then go to PHP's own error log; anything else, such as an invalid level, still throws. See [Decorators](/features/decorators#fail-safe-logging).

## Next Steps

- [Log Rotation](/features/rotation) — Size and time limits, retention, many processes
- [Formatters](/features/formatters) — Templates and output formats
- [Context](/features/context) — What is written for each kind of value, limits and redaction
- [Decorators](/features/decorators) — Several destinations, fail-safe logging, shared context
