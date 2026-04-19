<?php

declare(strict_types=1);

namespace Solo\Logger\Formatter;

use DateTimeInterface;

/**
 * Formats a single log record into the string that will be written to the log file.
 * The returned string MUST NOT include a trailing newline — the Logger appends one.
 */
interface FormatterInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function format(string $level, string|\Stringable $message, array $context, DateTimeInterface $time): string;
}
