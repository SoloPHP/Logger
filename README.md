# PSR-3 Logger Implementation

A lightweight PSR-3 compliant logger implementation with timezone support and file handling.

## Installation

Install via composer:

```bash
composer require solophp/logger
```

## Usage

Basic usage:

```php
use Solo\Logger;

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

## Output Format

Logs are written in the following format:
```text
[2024-01-30 15:30:45] INFO: Your message here
[2024-01-30 15:30:46] ERROR: Error message with context: {context_value}
```

## License

MIT License. See LICENSE file for details.