<?php

declare(strict_types=1);

namespace Solo\Logger\Clock;

use Psr\Clock\ClockInterface;

final readonly class SystemClock implements ClockInterface
{
    public function __construct(private \DateTimeZone $timezone = new \DateTimeZone('UTC'))
    {
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->timezone);
    }
}
