<?php

declare(strict_types=1);

namespace Solo\Logger\Writer;

/** The destination failed: a record could not be written, rotated or retained. */
final class WriteException extends \RuntimeException
{
}
