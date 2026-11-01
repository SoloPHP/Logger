<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Formatter;

use PHPUnit\Framework\TestCase;
use Solo\Logger\Formatter\LogfmtFormatter;
use Solo\Logger\LogRecord;

final class LogfmtFormatterTest extends TestCase
{
    public function testContextKeysAreNamespacedAndReversiblyEncoded(): void
    {
        $context = [
            'time' => 'wrong', 'level' => 'debug', 'message' => 'replacement', 'a b' => 'space',
            'a=b' => 'equals', 'a_b' => 'underscore', 'quote"key' => 'quote', '%' => 'percent',
            '%20' => 'literal percent', '' => 'empty key', 123 => 'numeric',
        ];
        $pairs = $this->parse($this->format('Original', $context));
        self::assertSame('error', $pairs['level']);
        self::assertSame('Original', $pairs['message']);
        self::assertSame('2026-10-31T12:00:00.000000+00:00', $pairs['time']);
        $decodedContext = [];
        foreach ($pairs as $key => $value) {
            if (str_starts_with($key, 'context.')) {
                $decodedContext[rawurldecode(substr($key, 8))] = $value;
            }
        }
        self::assertSame($context, $decodedContext);
    }

    public function testValuesRoundTripWithControlCharactersQuotesBackslashesAndUnicode(): void
    {
        $value = "\"quoted\" \\backslash /path = equals\n\r\t\0 Україна";
        $output = $this->format($value, ['value' => $value, 'bool' => false, 'null' => null, 'array' => ['a', 'b']]);
        self::assertStringNotContainsString("\n", $output);
        $pairs = $this->parse($output);
        self::assertSame($value, $pairs['message']);
        self::assertSame($value, $pairs['context.value']);
        self::assertSame('false', $pairs['context.bool']);
        self::assertSame('null', $pairs['context.null']);
        self::assertSame(['a', 'b'], json_decode($pairs['context.array'], true));
    }

    public function testPathsAndUnicodeStayReadable(): void
    {
        $output = $this->format('GET /api/users Україна', ['path' => '/a/b', 'tags' => ['київ']]);
        self::assertStringContainsString('message="GET /api/users Україна"', $output);
        self::assertStringContainsString('context.path="/a/b"', $output);
        self::assertStringContainsString('context.tags="[\"київ\"]"', $output);
    }

    public function testMessageIsInterpolatedAndChannelFollowsTheLevel(): void
    {
        $record = new LogRecord(
            'info',
            'User {id} signed in',
            ['id' => 42],
            new \DateTimeImmutable('2026-10-31T12:00:00+00:00'),
            'auth',
        );
        self::assertSame(
            'time="2026-10-31T12:00:00.000000+00:00" level="info" channel="auth" message="User 42 signed in" '
            . 'context.id="42"',
            (new LogfmtFormatter())->format($record),
        );
    }

    private function format(string $message, array $context): string
    {
        return (new LogfmtFormatter())->format(new LogRecord(
            'error',
            $message,
            $context,
            new \DateTimeImmutable('2026-10-31T12:00:00+00:00')
        ));
    }

    private function parse(string $line): array
    {
        $pairs = [];
        while ($line !== '') {
            self::assertSame(1, preg_match('/\A([^\s="\\x00-\\x1f]+)=("(?:[^"\\\\]|\\\\.)*")(?: |$)/s', $line, $match));
            self::assertArrayNotHasKey($match[1], $pairs);
            $pairs[$match[1]] = json_decode($match[2], true, flags: JSON_THROW_ON_ERROR);
            $line = substr($line, strlen($match[0]));
        }
        return $pairs;
    }
}
