<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Formatter;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Solo\Logger\Formatter\JsonFormatter;

class JsonFormatterTest extends TestCase
{
    private DateTimeImmutable $time;

    protected function setUp(): void
    {
        $this->time = new DateTimeImmutable('2026-04-19T10:15:30+00:00', new DateTimeZone('UTC'));
    }

    public function testReservedKeysGetNamespacedUnderContext(): void
    {
        $json = (new JsonFormatter())->format('info', 'Msg', [
            'time' => 'user-time',
            'level' => 'user-level',
            'message' => 'user-msg',
            'safe' => 'ok',
        ], $this->time);

        $decoded = json_decode($json, true);
        $this->assertSame('2026-04-19T10:15:30+00:00', $decoded['time']);
        $this->assertSame('info', $decoded['level']);
        $this->assertSame('Msg', $decoded['message']);
        $this->assertSame('ok', $decoded['safe']);
        $this->assertSame('user-time', $decoded['context']['time']);
        $this->assertSame('user-level', $decoded['context']['level']);
        $this->assertSame('user-msg', $decoded['context']['message']);
    }

    public function testThrowableChainCaptured(): void
    {
        $root = new \LogicException('root cause', 42);
        $top = new \RuntimeException('top', 44, $root);

        $json = (new JsonFormatter())->format('error', 'failed', ['exception' => $top], $this->time);

        $decoded = json_decode($json, true);
        $this->assertSame('top', $decoded['exception']['message']);
        $this->assertSame(44, $decoded['exception']['code']);
        $this->assertSame('root cause', $decoded['exception']['previous']['message']);
        $this->assertSame(42, $decoded['exception']['previous']['code']);
    }

    public function testStringableObjectInContext(): void
    {
        $obj = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringified';
            }
        };

        $json = (new JsonFormatter())->format('info', 'Msg', ['obj' => $obj], $this->time);

        $this->assertSame('stringified', json_decode($json, true)['obj']);
    }

    public function testDateTimeBeatsStringableWhenBothApply(): void
    {
        $dtWithToString = new class ('2026-04-19T10:15:30+00:00') extends \DateTimeImmutable implements \Stringable {
            public function __toString(): string
            {
                return 'wrong';
            }
        };

        $json = (new JsonFormatter('Y-m-d'))->format('info', 'm', ['when' => $dtWithToString], $this->time);

        $this->assertSame('2026-04-19', json_decode($json, true)['when']);
    }

    public function testNestedArrayNormalized(): void
    {
        $json = (new JsonFormatter())->format(
            'error',
            'm',
            ['nested' => ['err' => new \RuntimeException('boom')]],
            $this->time,
        );

        $this->assertSame('boom', json_decode($json, true)['nested']['err']['message']);
    }

    public function testPlainObjectRenderedAsClassMarker(): void
    {
        $json = (new JsonFormatter())->format('info', 'm', ['obj' => new \stdClass()], $this->time);

        $this->assertSame(['__class' => \stdClass::class], json_decode($json, true)['obj']);
    }

    public function testInvalidUtf8FallsBack(): void
    {
        $json = (new JsonFormatter())->format('error', 'bad', ['payload' => "\xB1\x31"], $this->time);

        $this->assertNotSame('', $json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('error', $decoded['level'] ?? null);
    }
}
