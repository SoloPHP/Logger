<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Formatter;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Solo\Logger\Formatter\LineFormatter;

class LineFormatterTest extends TestCase
{
    public function testArrayContextKeyIsNotInterpolated(): void
    {
        $time = new DateTimeImmutable('2026-04-19T10:15:30+00:00', new DateTimeZone('UTC'));
        $formatter = new LineFormatter();

        $result = $formatter->format('info', 'Roles: {roles}', ['roles' => ['a', 'b']], $time);

        $this->assertSame('[2026-04-19T10:15:30+00:00] INFO: Roles: {roles}', $result);
    }
}
