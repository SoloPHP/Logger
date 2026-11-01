<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Support;

use Psr\Clock\ClockInterface;

final class MutableClock implements ClockInterface
{
    public function __construct(public \DateTimeImmutable $time = new \DateTimeImmutable('2026-10-31T12:00:00+00:00'))
    {
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time = $this->time->modify(sprintf('%+d seconds', $seconds));
    }
}
