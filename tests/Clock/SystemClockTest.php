<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Clock;

use PHPUnit\Framework\TestCase;
use Solo\Logger\Clock\SystemClock;

final class SystemClockTest extends TestCase
{
    public function testDefaultClockUsesUtcIndependentlyOfGlobalTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        try {
            $before = time();
            $now = (new SystemClock())->now();
            self::assertSame('UTC', $now->getTimezone()->getName());
            self::assertGreaterThanOrEqual($before, $now->getTimestamp());
            self::assertLessThanOrEqual(time(), $now->getTimestamp());
        } finally {
            date_default_timezone_set($previous);
        }
    }
}
