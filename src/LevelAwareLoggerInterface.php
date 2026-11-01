<?php

declare(strict_types=1);

namespace Solo\Logger;

use Psr\Log\LoggerInterface;

/** A PSR-3 logger that tells in advance whether it would write a level, so callers can skip costly context. */
interface LevelAwareLoggerInterface extends LoggerInterface
{
    /**
     * Whether a record of this level would be written.
     *
     * @throws \Psr\Log\InvalidArgumentException when the level is not one of the eight PSR-3 levels
     */
    public function isHandling(string $level): bool;
}
