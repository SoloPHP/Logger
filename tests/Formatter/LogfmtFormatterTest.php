<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Formatter;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Solo\Logger\Formatter\LogfmtFormatter;

class LogfmtFormatterTest extends TestCase
{
    private DateTimeImmutable $time;

    protected function setUp(): void
    {
        $this->time = new DateTimeImmutable('2026-04-19T10:15:30+00:00', new DateTimeZone('UTC'));
    }

    public function testThrowableCoercedToMessage(): void
    {
        $out = (new LogfmtFormatter())->format(
            'error',
            'failed',
            ['exception' => new \RuntimeException('boom')],
            $this->time,
        );

        $this->assertStringContainsString('exception=boom', $out);
    }

    public function testArrayContextSerializedAsJson(): void
    {
        $out = (new LogfmtFormatter())->format('info', 'Msg', ['arr' => ['a', 'b']], $this->time);

        $this->assertStringContainsString('arr="[\\"a\\",\\"b\\"]"', $out);
    }

    public function testNullValueRenderedAsEmpty(): void
    {
        $out = (new LogfmtFormatter())->format('info', 'Msg', ['n' => null], $this->time);

        $this->assertStringContainsString('n=""', $out);
    }

    public function testStringableObject(): void
    {
        $obj = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringified';
            }
        };

        $out = (new LogfmtFormatter())->format('info', 'Msg', ['obj' => $obj], $this->time);

        $this->assertStringContainsString('obj=stringified', $out);
    }
}
