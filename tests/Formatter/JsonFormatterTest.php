<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Formatter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Logger\Context\ContextNormalizer;
use Solo\Logger\Formatter\JsonFormatter;
use Solo\Logger\LogRecord;

final class JsonFormatterTest extends TestCase
{
    public function testContextCannotOverwriteMetadataInEitherOrder(): void
    {
        $context = ['context' => 'user data', 'message' => 'replacement', 'level' => 'debug', 'time' => 'other'];
        foreach ([$context, array_reverse($context, true)] as $values) {
            $json = (new JsonFormatter())->format($this->record($values));
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('error', $decoded['level']);
            self::assertSame('Original', $decoded['message']);
            self::assertSame('2026-10-31T12:00:00.000000+00:00', $decoded['time']);
            self::assertSame($values, $decoded['context']);
            self::assertSame(['time', 'level', 'message', 'context'], array_keys($decoded));
        }
    }

    public function testEmptyAndNumericContextsKeepAnObjectSchema(): void
    {
        foreach ([[], [0 => 'zero', 1 => 'one']] as $context) {
            $decoded = json_decode((new JsonFormatter())->format($this->record($context)), flags: JSON_THROW_ON_ERROR);
            self::assertInstanceOf(\stdClass::class, $decoded->context);
            self::assertSame($context, (array) $decoded->context);
        }
    }

    public function testInvalidUtf8PreservesHealthyFieldsAndExceptions(): void
    {
        $context = ['payload' => "\xB1\x31", 'request_id' => 'req-42', 'exception' => new \RuntimeException('failure')];
        $json = (new JsonFormatter())->format($this->record($context));
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame("\u{FFFD}1", $decoded['context']['payload']);
        self::assertSame('req-42', $decoded['context']['request_id']);
        self::assertSame('failure', $decoded['context']['exception']['message']);
    }

    public function testMessageIsInterpolatedAndStaysOnOneLine(): void
    {
        $output = (new JsonFormatter())->format($this->record(['id' => 42], "User {id}\n\"quoted\" / Україна"));
        self::assertStringNotContainsString("\n", $output);
        self::assertSame("User 42\n\"quoted\" / Україна", json_decode($output, true)['message']);
    }

    public function testMessageNamesAnExceptionWhileTheContextDescribesIt(): void
    {
        $record = $this->record(['exception' => new \LogicException('boom')], 'Failed: {exception}');
        $decoded = json_decode((new JsonFormatter())->format($record), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Failed: LogicException: boom', $decoded['message']);
        self::assertSame('LogicException', $decoded['context']['exception']['class']);
    }

    public function testChannelFollowsTheLevelWhenTheRecordHasOne(): void
    {
        $time = new \DateTimeImmutable('2026-10-31T12:00:00+00:00');
        $record = new LogRecord('error', 'Original', [], $time, 'payments');
        $decoded = json_decode((new JsonFormatter())->format($record), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['time', 'level', 'channel', 'message', 'context'], array_keys($decoded));
        self::assertSame('payments', $decoded['channel']);
    }

    #[DataProvider('dateFormats')]
    public function testEveryDateFollowsOneFormat(JsonFormatter $formatter, string $time, string $due): void
    {
        $record = new LogRecord(
            'info',
            'Due',
            ['due' => new \DateTimeImmutable('2026-12-24T10:00:00+00:00')],
            new \DateTimeImmutable('2026-10-31T12:00:00+00:00'),
        );
        $decoded = json_decode($formatter->format($record), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([$time, $due], [$decoded['time'], $decoded['context']['due']]);
    }

    public static function dateFormats(): array
    {
        $default = ['2026-10-31T12:00:00.000000+00:00', '2026-12-24T10:00:00.000000+00:00'];
        $normalizer = new ContextNormalizer(dateFormat: 'd.m.Y');
        $redacting = new ContextNormalizer(redactKeys: ['password']);
        return [
            'default' => [new JsonFormatter(), ...$default],
            'the formatter\'s' => [new JsonFormatter('Y-m-d'), '2026-10-31', '2026-12-24'],
            'the normalizer\'s' => [new JsonFormatter(normalizer: $normalizer), '31.10.2026', '24.12.2026'],
            'the formatter\'s over the normalizer\'s' => [
                new JsonFormatter('Y-m-d', $redacting),
                '2026-10-31',
                '2026-12-24',
            ],
        ];
    }

    private function record(array $context, string $message = 'Original'): LogRecord
    {
        return new LogRecord('error', $message, $context, new \DateTimeImmutable('2026-10-31T12:00:00+00:00'));
    }
}
