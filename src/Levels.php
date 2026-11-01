<?php

declare(strict_types=1);

namespace Solo\Logger;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/** @internal The eight PSR-3 levels, shared by the logger and the decorators; not part of the public API. */
final class Levels
{
    /** The severity of each level, the most severe first. */
    public const array SEVERITY = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT => 1,
        LogLevel::CRITICAL => 2,
        LogLevel::ERROR => 3,
        LogLevel::WARNING => 4,
        LogLevel::NOTICE => 5,
        LogLevel::INFO => 6,
        LogLevel::DEBUG => 7,
    ];

    /**
     * @phpstan-assert key-of<self::SEVERITY> $level
     * @throws InvalidArgumentException when the level is not one of the eight PSR-3 levels
     */
    public static function assertValid(mixed $level): void
    {
        if (!is_string($level) || !isset(self::SEVERITY[$level])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown log level %s; expected one of: %s.',
                is_string($level) ? '"' . $level . '"' : get_debug_type($level),
                implode(', ', array_keys(self::SEVERITY)),
            ));
        }
    }
}
