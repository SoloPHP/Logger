<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Formatter;

use PHPUnit\Framework\TestCase;
use Solo\Logger\Formatter\LineFormatter;
use Solo\Logger\Context\ContextNormalizer;
use Solo\Logger\LogRecord;
use Solo\Logger\Tests\Fixtures\CountingStringable;
use Solo\Logger\Tests\Fixtures\Status;

final class LineFormatterTest extends TestCase
{
    public function testInterpolatesScalarsAndPreservesDiagnosticContext(): void
    {
        $output = $this->format('User {id}, enabled={enabled}, roles={roles}, price={price}, status={status}', [
            'id' => 42, 'enabled' => false, 'roles' => ['admin'], 'price' => 1.0, 'status' => Status::Active,
        ]);
        self::assertStringContainsString(
            'INFO: User 42, enabled=false, roles={roles}, price=1.0, status=active',
            $output,
        );
        self::assertStringContainsString('"roles":["admin"]', $output);
        self::assertStringContainsString('"price":1.0', $output);
    }

    public function testDefaultFormatIncludesExceptionEvenWithoutAPlaceholder(): void
    {
        $output = $this->format('Request failed', ['exception' => new \RuntimeException('database unreachable')]);
        self::assertStringContainsString('database unreachable', $output);
        self::assertStringContainsString('"trace":', $output);
    }

    public function testExceptionPlaceholderReadsClassAndMessage(): void
    {
        $output = $this->format('{method} {path}: {exception}', [
            'method' => 'GET', 'path' => '/tasks', 'exception' => new \RuntimeException('Connection refused'),
        ]);
        self::assertStringStartsWith(
            '[2026-10-31T12:00:00.000000+00:00] INFO: GET /tasks: RuntimeException: Connection refused '
            . '{"method":"GET",',
            $output,
        );
    }

    public function testChannelPrefixesTheLevelOnlyWhenTheRecordHasOne(): void
    {
        $time = new \DateTimeImmutable('2026-10-31T12:00:00+00:00');
        $formatter = new LineFormatter();
        self::assertSame(
            '[2026-10-31T12:00:00.000000+00:00] INFO: ok',
            $formatter->format(new LogRecord('info', 'ok', [], $time)),
        );
        self::assertSame(
            '[2026-10-31T12:00:00.000000+00:00] payments.INFO: ok',
            $formatter->format(new LogRecord('info', 'ok', [], $time, 'payments')),
        );
        self::assertSame(
            'pay\\nments.INFO',
            (new LineFormatter('{channel}{level}'))->format(new LogRecord('info', 'ok', [], $time, "pay\nments")),
        );
    }

    public function testMessageAndInterpolatedValuesCannotIntroduceNewLogLines(): void
    {
        $output = $this->format("Hello\n{name}\r\n", ['name' => "user\nforged"]);
        self::assertStringNotContainsString("\n", $output);
        self::assertStringNotContainsString("\r", $output);
        self::assertStringContainsString('Hello\nuser\nforged\r\n', $output);
    }

    public function testControlCharactersCannotReachTheTerminal(): void
    {
        $record = $this->record("Login {user}\x07", ['user' => "admin\e[2K\e[1A\x08\x7F"]);
        self::assertSame('Login admin\033[2K\033[1A\b\177\a', (new LineFormatter('{message}'))->format($record));
        // The appended context is JSON, which escapes every C0 control character itself.
        self::assertSame(0, preg_match('/[\x00-\x1F]/', (new LineFormatter())->format($record)));
    }

    public function testInvalidUtf8InTheMessageIsSubstituted(): void
    {
        $output = $this->format("bad \xB1 byte");
        self::assertSame(1, preg_match('//u', $output));
        self::assertStringContainsString("bad \u{FFFD} byte", $output);
    }

    public function testCustomTemplateCanExplicitlyOmitContext(): void
    {
        $formatter = new LineFormatter("{time}\t{level}\t{message}", 'Y-m-d');
        self::assertSame("2026-10-31\tINFO\tok", $formatter->format($this->record('{value}', ['value' => 'ok'])));
    }

    public function testContextDatesFollowTheTimeFormat(): void
    {
        $record = $this->record('Due {due}', ['due' => new \DateTimeImmutable('2026-12-24T10:00:00+00:00')]);
        self::assertSame(
            '[2026-10-31] INFO: Due 2026-12-24 {"due":"2026-12-24"}',
            (new LineFormatter(dateFormat: 'Y-m-d'))->format($record),
        );
    }

    public function testContextIsNotConvertedWhenNothingWritesIt(): void
    {
        $value = new CountingStringable();
        $record = $this->record('No placeholders', ['value' => $value]);
        self::assertSame('No placeholders', (new LineFormatter('{message}'))->format($record));
        self::assertSame(0, $value->conversions);
        (new LineFormatter())->format($record);
        self::assertSame(1, $value->conversions);
        (new LineFormatter('{message}'))->format($this->record('Value {value}', ['value' => $value]));
        self::assertSame(2, $value->conversions);
    }

    public function testTemplateMustFitOnOneLine(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LineFormatter("{message}\n{context}");
    }

    public function testDateFormatMustFitOnOneLine(): void
    {
        foreach ([["Y-m-d\nH:i", null], [null, new ContextNormalizer(dateFormat: "Y\r")]] as [$format, $normalizer]) {
            try {
                new LineFormatter(dateFormat: $format, normalizer: $normalizer);
                self::fail('Expected the date format to be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('The template and the date format must fit on one line.', $e->getMessage());
            }
        }
    }

    private function format(string $message, array $context = []): string
    {
        return (new LineFormatter())->format($this->record($message, $context));
    }

    private function record(string $message, array $context): LogRecord
    {
        return new LogRecord('info', $message, $context, new \DateTimeImmutable('2026-10-31T12:00:00+00:00'));
    }
}
