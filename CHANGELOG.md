# Changelog

All notable changes to this project will be documented in this file.

## [2.0.0] - 2025-08-03

### Added
- Modular architecture with clear separation of concerns
- PHP 8.1+ features (typed properties, union types, arrow functions)
- Comprehensive test suite with PHPUnit
- CodeSniffer integration with PSR-12 standard
- Enhanced documentation with architecture guide

### Changed
- Refactored to use PHP 8.1+ features
- Decomposed monolithic Logger into specialized components:
  - `LoggerConfiguration` for settings management
  - `FileSystemManager` for file operations
  - `ContextInterpolator` for message interpolation
  - `LogRotator` for rotation logic
- Updated minimum PHP version to 8.1
- Improved code organization and maintainability

### Removed
- Support for PHP 7.4 (minimum version now 8.1)

## [1.0.0] - 2024-01-30

### Added
- PSR-3 Logger interface implementation
- File-based logging with automatic directory creation
- Timezone support
- Context interpolation
- Log rotation with size and time-based strategies
- Thread-safe file operations 