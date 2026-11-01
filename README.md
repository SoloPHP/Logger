# Solo Logger

PSR-3 logger for PHP: coordinated file rotation, standard error for containers, bounded context, and one line per record in line, JSON or logfmt format.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/logger.svg)](https://packagist.org/packages/solophp/logger)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

## Features

- **PSR-3 and PSR-20** — the eight standard levels, a minimum level, `isHandling()`, channels, and any PSR-20 clock for deterministic timestamps
- **Files and Streams** — rotation by size, interval or calendar, with retention, safe for many processes appending to one file; standard error for containers and workers
- **Three Formats** — line, NDJSON and logfmt; every record is one physical line with a fixed shape and microsecond times
- **Bounded Context** — exceptions, dates, enums and Stringables are converted safely; depth, entries and string length are capped; the keys you name are redacted
- **Safe Output** — control characters are escaped, invalid UTF-8 is replaced, symlinked log files are refused, file modes can be restricted
- **Composable** — decorators send records to several destinations, keep output failures away from requests and add shared context
- **Fails Loudly** — bad configuration throws on construction; a failed write throws `WriteException`, and a failed rotation keeps the record

## Installation

```bash
composer require solophp/logger
```

## Quick Example

```php
use Psr\Log\LogLevel;
use Solo\Logger\Formatter\JsonFormatter;
use Solo\Logger\Logger;
use Solo\Logger\Rotation\RotationOptions;
use Solo\Logger\Rotation\Schedule;

$logger = Logger::file(
    path: '/var/log/app/app.log',
    minLevel: LogLevel::INFO,
    formatter: new JsonFormatter(),
    rotation: new RotationOptions(maxBytes: 10 * 1024 * 1024, schedule: Schedule::Daily, maxFiles: 14),
);

$logger->info('User {id} signed in', ['id' => 42]);
// {"time":"2026-11-01T09:30:00.123456+00:00","level":"info","message":"User 42 signed in","context":{"id":42}}

$logger->error('Payment failed', ['order_id' => 1001, 'exception' => $exception]);
```

## Documentation

**[Full Documentation](https://solophp.github.io/Logger/)**

- [Installation](https://solophp.github.io/Logger/guide/installation)
- [Quick Start](https://solophp.github.io/Logger/guide/quick-start)
- [Log Rotation](https://solophp.github.io/Logger/features/rotation)
- [Formatters](https://solophp.github.io/Logger/features/formatters)
- [Context](https://solophp.github.io/Logger/features/context)
- [Streams](https://solophp.github.io/Logger/features/streams)
- [Decorators](https://solophp.github.io/Logger/features/decorators)
- [Extending](https://solophp.github.io/Logger/features/extending)
- [API Reference](https://solophp.github.io/Logger/api/logger)

Upgrading from 1.x? See [UPGRADE.md](UPGRADE.md). Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## Development

```bash
composer install
composer check          # PSR-12, PHPStan at max level, PHPUnit
composer test-coverage  # HTML report in coverage/, needs PCOV or Xdebug
```

Permission tests need a non-root POSIX user; the multi-process tests need `proc_open`. CI runs PHP 8.3, 8.4 and 8.5 on Linux, and the tests on Windows.

## Requirements

- PHP 8.3+

## License

MIT License. See [LICENSE](LICENSE) for details.
