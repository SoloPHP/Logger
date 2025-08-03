# Solo PSR-3 Logger 

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/logger.svg)](https://packagist.org/packages/solophp/logger)
[![License](https://img.shields.io/packagist/l/solophp/logger.svg)](https://github.com/solophp/logger/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/logger.svg)](https://packagist.org/packages/solophp/logger)

A lightweight PSR-3 compliant logger implementation with timezone support, file handling, and log rotation capabilities.

## Installation

Install via composer:

```bash
composer require solophp/logger
```

## Usage

Basic usage:

```php
use Solo\Logger\Logger;

// Initialize logger
$logger = new Logger('/path/to/log/file.log');

// Log messages
$logger->info('Application started');
$logger->error('An error occurred', ['error_code' => 500]);
```

With custom timezone:

```php
$logger = new Logger('/path/to/log/file.log', 'America/New_York');
```

Change log file or timezone at runtime:

```php
$logger->setLogFile('/path/to/another/file.log');
$logger->setTimezone('Asia/Tokyo');
```

## Features

- PSR-3 Logger Interface compliance
- Automatic log directory creation
- Custom timezone support
- Context interpolation
- Safe file handling with locks
- Support for all PSR-3 log levels
- **Log rotation** with size and time-based strategies
- **Automatic cleanup** of old log files
- **Thread-safe** rotation operations
- **Modular architecture** with clear separation of concerns

## Requirements

- PHP 7.4 or higher
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

## Output Format

Logs are written in the following format:
```text
[2024-01-30 15:30:45] INFO: Your message here
[2024-01-30 15:30:46] ERROR: Error message with context: {context_value}
```

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run tests with coverage
composer test-coverage

# Check code style
composer cs

# Fix code style
composer cs-fix
```

## License

MIT License. See LICENSE file for details.