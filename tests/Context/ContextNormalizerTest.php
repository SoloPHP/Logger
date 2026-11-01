<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Logger\Context\ContextNormalizer;
use Solo\Logger\Formatter\FormatterInterface;
use Solo\Logger\Formatter\JsonFormatter;
use Solo\Logger\Formatter\LineFormatter;
use Solo\Logger\Formatter\LogfmtFormatter;
use Solo\Logger\LogRecord;
use Solo\Logger\Tests\Fixtures\CountingStringable;
use Solo\Logger\Tests\Fixtures\Status;
use Solo\Logger\Tests\Fixtures\Suit;
use Solo\Logger\Tests\Fixtures\ThrowingStringable;

final class ContextNormalizerTest extends TestCase
{
    #[DataProvider('formatters')]
    public function testRecursiveContextIsBoundedInEveryFormat(FormatterInterface $formatter): void
    {
        $cycle = [];
        $cycle['self'] =& $cycle;
        $record = new LogRecord('error', 'Original failure', ['payload' => $cycle], new \DateTimeImmutable());
        $output = $formatter->format($record);
        self::assertStringContainsString('[depth limit]', $output);
        self::assertLessThan(2000, strlen($output));
    }

    #[DataProvider('formatters')]
    public function testFailingConversionsCannotBreakLoggingInAnyFormat(FormatterInterface $formatter): void
    {
        $throws = new ThrowingStringable();
        $json = new class implements \JsonSerializable {
            #[\Override]
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('Must not invoke arbitrary serialization.');
            }
        };
        $record = new LogRecord(
            'error',
            'Original failure',
            compact('throws', 'json'),
            new \DateTimeImmutable(),
        );
        $output = $formatter->format($record);
        self::assertStringContainsString('Original failure', $output);
        self::assertStringContainsString('unprintable:', $output);
    }

    public static function formatters(): array
    {
        return [[new JsonFormatter()], [new LineFormatter()], [new LogfmtFormatter()]];
    }

    public function testSilencedAndExcludedErrorsDoNotMakeAValueUnprintable(): void
    {
        $silenced = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                @trigger_error('harmless', E_USER_WARNING);
                return 'silenced';
            }
        };
        $deprecated = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                trigger_error('old API', E_USER_DEPRECATED);
                return 'excluded';
            }
        };
        $level = error_reporting(E_ALL & ~E_USER_DEPRECATED);
        try {
            $context = (new ContextNormalizer())->normalize(compact('silenced', 'deprecated'));
        } finally {
            error_reporting($level);
        }
        self::assertSame(['silenced' => 'silenced', 'deprecated' => 'excluded'], $context);
    }

    public function testWarningRaisedByAConversionMakesTheValueUnprintable(): void
    {
        $warns = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                trigger_error('conversion warning', E_USER_WARNING);
                return 'unreachable';
            }
        };
        // PHPUnit masks error_reporting while a test runs; an application reports warnings.
        $level = error_reporting(E_ALL);
        try {
            $context = (new ContextNormalizer())->normalize(['warns' => $warns]);
        } finally {
            error_reporting($level);
        }
        self::assertSame(['warns' => '[unprintable:Stringable@anonymous]'], $context);
    }

    public function testFramesInsidePhpNameOnlyTheCall(): void
    {
        try {
            array_map(static fn(): never => throw new \RuntimeException('in a callback'), [1]);
        } catch (\RuntimeException $exception) {
        }
        $trace = (new ContextNormalizer())->normalize(['exception' => $exception])['exception']['trace'];
        // PHP 8.3 names the closure Namespace\{closure}, PHP 8.4 {closure:Class::method():line}.
        self::assertMatchesRegularExpression(
            '~^\[internal\] ' . preg_quote(self::class, '~') . '::[\w\\\\]*\{closure[^}]*\}\(\)$~',
            $trace[0],
        );
    }

    #[DataProvider('invalidLimits')]
    public function testLimitsOutOfRangeAreRejected(int $maxDepth, int $maxItems, int $maxStringLength): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ContextNormalizer($maxDepth, $maxItems, $maxStringLength);
    }

    public static function invalidLimits(): array
    {
        return [
            'no depth' => [0, 1000, 16384],
            'too deep' => [65, 1000, 16384],
            'no items' => [8, 0, 16384],
            'no string length' => [8, 1000, 0],
        ];
    }

    public function testErrorHandlerIsRestoredAfterAUserConversion(): void
    {
        $handler = static fn(): bool => true;
        set_error_handler($handler);
        try {
            (new ContextNormalizer())->normalize(['value' => new ThrowingStringable()]);
            $previous = set_error_handler($handler);
            restore_error_handler();
            self::assertSame($handler, $previous);
        } finally {
            restore_error_handler();
        }
    }

    public function testExceptionChainNamesStackFramesWithoutArguments(): void
    {
        try {
            $this->failWith('secret-argument');
        } catch (\RuntimeException $exception) {
        }
        $context = (new ContextNormalizer())->normalize(['exception' => $exception]);

        self::assertSame(\RuntimeException::class, $context['exception']['class']);
        self::assertSame('outer', $context['exception']['message']);
        self::assertSame(7, $context['exception']['code']);
        self::assertSame('root', $context['exception']['previous'][0]['message']);
        self::assertArrayNotHasKey('previous', $context['exception']['previous'][0]);
        self::assertMatchesRegularExpression(
            '~^.+[/\\\\]ContextNormalizerTest\.php:\d+ ' . preg_quote(self::class, '~') . '->failWith\(\)$~',
            $context['exception']['trace'][0],
        );
        self::assertStringNotContainsString('secret-argument', json_encode($context, JSON_THROW_ON_ERROR));
    }

    public function testLongExceptionChainKeepsItsRootCause(): void
    {
        $exception = new \RuntimeException('root cause');
        for ($i = 1; $i <= 20; ++$i) {
            $exception = new \RuntimeException('wrapper ' . $i, 0, $exception);
        }
        // Deeper in the context than the chain is long: the chain is a list, which costs no depth.
        $context = (new ContextNormalizer(maxDepth: 6))->normalize(['job' => ['error' => $exception]]);
        $chain = $context['job']['error']['previous'];
        self::assertCount(20, $chain);
        self::assertSame(['wrapper 19', 'root cause'], [$chain[0]['message'], $chain[19]['message']]);
    }

    public function testExceptionChainIsReadNoFurtherThanTheItemLimit(): void
    {
        $exception = new \RuntimeException('root cause');
        for ($i = 0; $i < 50; ++$i) {
            $exception = new \RuntimeException('wrapper', 0, $exception);
        }
        $context = (new ContextNormalizer(maxItems: 20))->normalize(['exception' => $exception]);
        self::assertLessThanOrEqual(21, count($context['exception']['previous']));
    }

    public function testDepthBoundsNestingButNotStringConversions(): void
    {
        $text = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                return 'text';
            }
        };
        $date = new \DateTimeImmutable('2026-10-31T12:00:00+00:00');
        $context = (new ContextNormalizer(maxDepth: 2))->normalize([
            'nested' => ['child' => ['deep' => true], 'date' => $date, 'text' => $text, 'status' => Status::Active],
        ]);
        self::assertSame([
            'child' => '[depth limit]',
            'date' => '2026-10-31T12:00:00.000000+00:00',
            'text' => 'text',
            'status' => 'active',
        ], $context['nested']);

        $flat = (new ContextNormalizer(maxDepth: 1))->normalize([
            'date' => $date,
            'list' => [1],
            'exception' => new \RuntimeException('nested'),
        ]);
        self::assertSame(
            ['date' => '2026-10-31T12:00:00.000000+00:00', 'list' => '[depth limit]', 'exception' => '[depth limit]'],
            $flat,
        );
    }

    public function testItemLimitKeepsEverySiblingBeforeNestedEntries(): void
    {
        $context = (new ContextNormalizer(maxItems: 6))->normalize([
            'payload' => range(1, 100),
            'request_id' => 'req-42',
            'user_id' => 7,
        ]);
        self::assertSame(['payload' => [1, 2, 3, '[item limit]'], 'request_id' => 'req-42', 'user_id' => 7], $context);

        $context = (new ContextNormalizer(maxItems: 2))->normalize(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);
        self::assertSame(['a' => 1, 'b' => 2, 'c' => '[item limit]'], $context);
    }

    public function testDeepExceptionTraceCannotCrowdOutLaterKeys(): void
    {
        $exception = $this->exceptionAtDepth(40);
        $context = (new ContextNormalizer(maxItems: 30))->normalize([
            'exception' => $exception,
            'request_id' => 'req-42',
        ]);
        self::assertSame('req-42', $context['request_id']);
        $trace = $context['exception']['trace'];
        self::assertSame('[item limit]', $trace[array_key_last($trace)]);
    }

    public function testStringsAreTruncatedOnCharacterBoundaries(): void
    {
        $context = (new ContextNormalizer(maxStringLength: 5))->normalize([
            'ascii' => 'abcdefgh',
            'cyrillic' => 'Україна',
            'emoji' => '😀😀',
        ]);
        self::assertSame([
            'ascii' => 'abcde[truncated]',
            'cyrillic' => 'Ук[truncated]',
            'emoji' => '😀[truncated]',
        ], $context);
    }

    public function testSpecialValuesAreRepresentedWithoutExposingObjectProperties(): void
    {
        $resource = fopen('php://memory', 'r+');
        $closed = fopen('php://memory', 'r+');
        fclose($closed);
        try {
            $result = (new ContextNormalizer())->normalize([
                'resource' => $resource, 'closed' => $closed, 'nan' => NAN, 'inf' => INF, 'negative_inf' => -INF,
                'object' => (object) ['secret' => 'not for logs'],
                'anonymous' => new class {
                },
                'date' => new \DateTimeImmutable('2026-10-31T12:00:00+00:00'),
                'backed' => Status::Active,
                'pure' => Suit::Hearts,
            ]);
            self::assertSame([
                'resource' => '[resource:stream]',
                'closed' => '[resource (closed)]',
                'nan' => 'NAN',
                'inf' => 'INF',
                'negative_inf' => '-INF',
                'object' => '[object:stdClass]',
                'anonymous' => '[object:class@anonymous]',
                'date' => '2026-10-31T12:00:00.000000+00:00',
                'backed' => 'active',
                'pure' => 'Hearts',
            ], $result);
        } finally {
            fclose($resource);
        }
    }

    #[DataProvider('formatters')]
    public function testInvalidUtf8ContextNeverDiscardsHealthyFields(FormatterInterface $formatter): void
    {
        $record = new LogRecord(
            'error',
            'Payload {payload}',
            ['payload' => "\xB1\x31", 'request_id' => 'req-42'],
            new \DateTimeImmutable(),
        );
        $output = $formatter->format($record);
        self::assertSame(1, preg_match('//u', $output));
        self::assertStringContainsString('req-42', $output);
    }

    public function testEncodeWritesReadableJsonOnOneLine(): void
    {
        $data = ['path' => '/a/b', 'city' => 'Київ', 'price' => 1.0, 'bad' => "\xB1", 'text' => "a\nb"];
        self::assertSame(
            "{\"path\":\"/a/b\",\"city\":\"Київ\",\"price\":1.0,\"bad\":\"\u{FFFD}\",\"text\":\"a\\nb\"}",
            ContextNormalizer::encode($data),
        );
    }

    public function testValidUtf8ReplacesOnlyBrokenSequences(): void
    {
        self::assertSame('Київ', ContextNormalizer::validUtf8('Київ'));
        self::assertSame("a\u{FFFD}b", ContextNormalizer::validUtf8("a\xB1b"));
    }

    public function testDatesDefaultToRfc3339WithMicroseconds(): void
    {
        $date = new \DateTimeImmutable('2026-10-31T12:00:00.123456+02:00');
        self::assertSame(['at' => '2026-10-31T12:00:00.123456+02:00'], (new ContextNormalizer())->normalize([
            'at' => $date,
        ]));
    }

    public function testInterpolationReadsAnExceptionAsItsClassAndMessage(): void
    {
        $message = (new ContextNormalizer())->interpolate('{method} {path}: {exception}', [
            'method' => 'GET',
            'path' => '/tasks',
            'exception' => new \RuntimeException('Connection refused'),
        ]);
        self::assertSame('GET /tasks: RuntimeException: Connection refused', $message);
    }

    public function testInterpolationSpellsValuesAsTheContextDoes(): void
    {
        $message = (new ContextNormalizer())->interpolate(
            '{text} {int} {float} {bool} {null} {enum} {date} {object} {list} {missing}',
            [
                'text' => 'ann', 'int' => 42, 'float' => 1.0, 'bool' => false, 'null' => null,
                'enum' => Status::Active, 'date' => new \DateTimeImmutable('2026-10-31T12:00:00+00:00'),
                'object' => new \stdClass(), 'list' => ['a'],
            ],
        );
        self::assertSame(
            'ann 42 1.0 false null active 2026-10-31T12:00:00.000000+00:00 [object:stdClass] {list} {missing}',
            $message,
        );
    }

    public function testInterpolationConvertsOnlyTheValuesTheMessageNames(): void
    {
        $named = new CountingStringable();
        $other = new CountingStringable();
        $message = (new ContextNormalizer())->interpolate('Value {named}', ['named' => $named, 'other' => $other]);
        self::assertSame('Value converted', $message);
        self::assertSame([1, 0], [$named->conversions, $other->conversions]);
    }

    public function testInterpolatedMessageIsCutAndMadeValidUtf8(): void
    {
        $normalizer = new ContextNormalizer(maxStringLength: 8);
        self::assertSame('User ann[truncated]', $normalizer->interpolate('User {name} signed in', ['name' => 'ann']));
        self::assertSame("bad \u{FFFD}", $normalizer->interpolate("bad \xB1", []));
    }

    public function testRedactedKeysAreHiddenAtEveryDepthAndInPlaceholders(): void
    {
        $normalizer = new ContextNormalizer(redactKeys: ['password', 'Authorization']);
        $context = $normalizer->normalize([
            'user' => 'ann',
            'PASSWORD' => 's3cret',
            'headers' => ['authorization' => 'Bearer t0ken', 'accept' => 'application/json'],
            'password_hint' => 'pet name',
            'nested' => ['password' => ['old' => 'a', 'new' => 'b']],
        ]);
        self::assertSame([
            'user' => 'ann',
            'PASSWORD' => '[redacted]',
            'headers' => ['authorization' => '[redacted]', 'accept' => 'application/json'],
            'password_hint' => 'pet name',
            'nested' => ['password' => '[redacted]'],
        ], $context);
        self::assertSame(
            'Login ann with [redacted]',
            $normalizer->interpolate('Login {user} with {password}', ['user' => 'ann', 'password' => 's3cret']),
        );
    }

    public function testRedactionLeavesExceptionDescriptionsAlone(): void
    {
        $context = (new ContextNormalizer(redactKeys: ['message', 'code']))->normalize([
            'code' => '123456',
            'exception' => new \RuntimeException('boom', 7, new \LogicException('cause', 3)),
        ]);
        self::assertSame('[redacted]', $context['code']);
        self::assertSame(['boom', 7], [$context['exception']['message'], $context['exception']['code']]);
        $cause = $context['exception']['previous'][0];
        self::assertSame(['cause', 3], [$cause['message'], $cause['code']]);
    }

    public function testWithDateFormatKeepsTheLimitsAndTheRedaction(): void
    {
        $normalizer = (new ContextNormalizer(maxStringLength: 4, redactKeys: ['Token', '42']))->withDateFormat('Y');
        self::assertSame('Y', $normalizer->dateFormat);
        self::assertSame(
            ['at' => '2026', 'token' => '[redacted]', 42 => '[redacted]', 'name' => 'anna[truncated]'],
            $normalizer->normalize([
                'at' => new \DateTimeImmutable('2026-10-31'),
                'token' => 't',
                42 => 'x',
                'name' => 'annabel',
            ]),
        );
    }

    public function testEmptyRedactedKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ContextNormalizer(redactKeys: ['password', '']);
    }

    private function failWith(string $secret): never
    {
        throw new \RuntimeException('outer', 7, new \LogicException('root'));
    }

    private function exceptionAtDepth(int $depth): \RuntimeException
    {
        return $depth === 0 ? new \RuntimeException('deep') : $this->exceptionAtDepth($depth - 1);
    }
}
