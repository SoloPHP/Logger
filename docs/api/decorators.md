# Decorators

Each decorator extends `Psr\Log\AbstractLogger`, implements [`LevelAwareLoggerInterface`](/api/logger#levelawareloggerinterface) and wraps any `Psr\Log\LoggerInterface`. Its `isHandling()` throws `Psr\Log\InvalidArgumentException` for a level that is not one of the eight PSR-3 levels, before it asks a wrapped logger; `log()` leaves the level to the loggers it wraps. See [Decorators](/features/decorators) for how to combine them.

## FanOutLogger

```php
namespace Solo\Logger\Decorator;

final class FanOutLogger extends AbstractLogger implements LevelAwareLoggerInterface
{
    public function __construct(LoggerInterface ...$loggers);
}
```

`log()` passes the record to every logger, in order. When some of them throw, the others are still called, then the first failure is rethrown — the first that is not a `WriteException`, if there is one, so that a bug is not hidden behind an outage. Without loggers it writes nothing.

`isHandling()` is `true` when any logger handles the level; a logger that does not implement `LevelAwareLoggerInterface` counts as handling every level.

---

## FailSafeLogger

```php
namespace Solo\Logger\Decorator;

final class FailSafeLogger extends AbstractLogger implements LevelAwareLoggerInterface
{
    /** @param (\Closure(WriteException): void)|null $onFailure */
    public function __construct(LoggerInterface $inner, ?\Closure $onFailure = null);
}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$inner` | `LoggerInterface` | The logger whose output failures are caught |
| `$onFailure` | `?Closure` | Receives each `WriteException`; by default it goes to `error_log()` as `Logging failed: …` |

`log()` catches `Solo\Logger\Writer\WriteException` only. Anything else — `Psr\Log\InvalidArgumentException` for an unknown level, an exception from a formatter — reaches the caller, and so does whatever `$onFailure` throws.

`isHandling()` asks the wrapped logger; one that does not implement `LevelAwareLoggerInterface` counts as handling every level.

---

## ContextLogger

```php
namespace Solo\Logger\Decorator;

final class ContextLogger extends AbstractLogger implements LevelAwareLoggerInterface
{
    /** @param array<array-key, mixed> $context */
    public function __construct(LoggerInterface $inner, array $context);
}
```

`log()` passes the record on with `array_replace($context, $callContext)`: the shared keys first, a key of the call winning over a shared one.

`isHandling()` asks the wrapped logger; one that does not implement `LevelAwareLoggerInterface` counts as handling every level.
