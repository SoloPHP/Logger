<?php

declare(strict_types=1);

namespace Solo\Logger\Formatter;

use Solo\Logger\LogRecord;

interface FormatterInterface
{
    /** Format one record without a trailing newline. */
    public function format(LogRecord $record): string;
}
