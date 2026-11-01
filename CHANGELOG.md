# Changelog

## 2.0.0 — 2026-11-01

A rewrite around three parts — a writer, a formatter and a clock — with rotation coordinated across processes, one bounded line per record in every format, and output failures that throw instead of disappearing. See the [upgrade guide](UPGRADE.md).

### Breaking changes

- **PHP 8.3 or later**, and `psr/clock` as a new dependency.
- **Construction.** `Logger::file($path, …)` with named arguments replaces `new Logger($path, …)`. `Logger` is final; its constructor takes a writer, a formatter, a minimum level, a clock and a channel, and `setMinLevel()` is the only setter left.
- **Levels.** Only the eight PSR-3 levels are accepted; anything else, `'INFO'` in capitals included, throws `Psr\Log\InvalidArgumentException`.
- **Rotation options.** `RotationOptions(maxBytes:, intervalSeconds:, schedule:, maxFiles:)` replaces the setters. `null` disables a limit, zero or a negative value throws, and `maxFiles` without a trigger throws.
- **Archive names.** `app.log.YYYY-MM-DD_HH-MM-SS.uuuuuu+HHMM.N` instead of `app_YYYY-MM-DD_HH-MM-SS.log`. Retention ignores 1.x archives.
- **Output.**
  - Times carry microseconds and are UTC by default, no longer in `date.timezone`.
  - Every format replaces placeholders in the message, with `{exception}` reading `Class: message`, and cuts the message at 16 KiB.
  - JSON puts the context under `context`.
  - Logfmt quotes every value and prefixes context keys with `context.`.
  - The line format appends the context as JSON and escapes control characters.
  - Other objects are written as `[object:Class]`, exceptions with their stack frames but without arguments.
- **Failures throw.** Every output failure throws `Solo\Logger\Writer\WriteException`, a `RuntimeException`. A failed rotation still appends the record to the active file, then throws.
- **Custom formatters** implement `format(LogRecord $record): string`.

### Added

- **`FileWriter`.** Rotation by size, by interval or by calendar, and retention, under one lock that every process shares. It refuses symlinked or irregular files and takes `directoryPermissions` and `filePermissions`, which `Logger::file()` passes on.
- **Calendar rotation.** `RotationOptions(schedule: Schedule::Daily)` rotates after every midnight of the writer's clock; `Hourly`, `Weekly` and `Monthly` too, through daylight saving time.
- **`StreamWriter` and `Logger::stream()`**, for standard error and other streams.
- **Formatters.** `JsonFormatter` and `LogfmtFormatter` with a fixed schema. `LineFormatter` templates take the tokens `{time}`, `{channel}`, `{level}`, `{message}` and `{context}`.
- **`ContextNormalizer`.** Bounded depth, entries and string length, and `redactKeys`. `interpolate()`, `encode()` and `validUtf8()` let your own formatters write what the built-in ones write; `withDateFormat()` keeps a formatter's dates in one format.
- **Decorators** in `Solo\Logger\Decorator`: `FanOutLogger`, `FailSafeLogger` and `ContextLogger`.
- **Channels.** `new Logger(channel: …)` and `withChannel()`.
- **`isHandling()`** on `Logger` and on the decorators, through `LevelAwareLoggerInterface`.
- **Clocks.** `SystemClock`, or any PSR-20 clock, for record times and archive names.

## 1.0.0 — 2026-04-19

First release.
