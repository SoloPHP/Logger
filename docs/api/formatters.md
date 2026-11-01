# Formatters

## FormatterInterface

```php
namespace Solo\Logger\Formatter;

interface FormatterInterface
{
    /** Format one record without a trailing newline. */
    public function format(LogRecord $record): string;
}
```

Return the record without a trailing newline; the writer adds it. See [Custom Formatters](/features/extending#custom-formatters).

---

## LineFormatter

```php
final readonly class LineFormatter implements FormatterInterface
{
    public const string DEFAULT_TEMPLATE = '[{time}] {channel}{level}: {message}{context}';

    public function __construct(
        string $template = self::DEFAULT_TEMPLATE,
        ?string $dateFormat = null,
        ?ContextNormalizer $normalizer = null,
    );
}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$template` | `string` | The line, with the tokens `{time}`, `{channel}`, `{level}`, `{message}` and `{context}` |
| `$dateFormat` | `?string` | A `DateTimeInterface::format()` format for `{time}` and for dates in the context; `null` takes the normalizer's |
| `$normalizer` | `?ContextNormalizer` | Writes the message and converts the context; by default a new one |

**Throws:** `InvalidArgumentException` — the template or the date format contains a line break.

```text
[2026-11-01T09:30:00.123456+00:00] INFO: User 42 signed in {"id":42}
```

The message comes from the normalizer's [`interpolate()`](#contextnormalizer). `{channel}` is the channel and a dot, or nothing; `{context}` a space and the context as JSON, or nothing. The channel, the level and the message are escaped: control characters C-style, invalid UTF-8 as U+FFFD. The template and the date format are configuration, written as they are. See [LineFormatter](/features/formatters#lineformatter).

---

## JsonFormatter

```php
final readonly class JsonFormatter implements FormatterInterface
{
    public function __construct(?string $dateFormat = null, ?ContextNormalizer $normalizer = null);
}
```

```json
{"time":"2026-11-01T09:30:00.123456+00:00","level":"info","message":"User 42 signed in","context":{"id":42}}
```

The fields `time`, `level`, `message` and `context`, in that order, with `channel` after `level` when the record has one; `context` is always an object. `message` comes from the normalizer's `interpolate()`. `$dateFormat` formats the time and the dates in the context; `null` takes the normalizer's. See [JsonFormatter](/features/formatters#jsonformatter).

---

## LogfmtFormatter

```php
final readonly class LogfmtFormatter implements FormatterInterface
{
    public function __construct(?string $dateFormat = null, ?ContextNormalizer $normalizer = null);
}
```

```text
time="2026-11-01T09:30:00.123456+00:00" level="info" message="User 42 signed in" context.id="42"
```

Every value quoted with JSON string escaping; `channel` after `level` when the record has one; context keys prefixed with `context.` and percent-encoded. `$dateFormat` formats the time and the dates in the context; `null` takes the normalizer's. See [LogfmtFormatter](/features/formatters#logfmtformatter).

---

## ContextNormalizer

```php
namespace Solo\Logger\Context;

final readonly class ContextNormalizer
{
    public const string DEFAULT_DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    /** @param list<string> $redactKeys */
    public function __construct(
        int $maxDepth = 8,
        int $maxItems = 1000,
        int $maxStringLength = 16384,
        public string $dateFormat = self::DEFAULT_DATE_FORMAT,
        array $redactKeys = [],
    );

    public function withDateFormat(string $dateFormat): self;

    /**
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    public function normalize(array $context): array;

    /** @param array<array-key, mixed> $context */
    public function interpolate(string $message, array $context): string;

    public static function encode(mixed $data): string;

    public static function validUtf8(string $text): string;
}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$maxDepth` | `int` | How deep arrays and exceptions nest, 1–64 |
| `$maxItems` | `int` | How many entries the whole context keeps, at least 1 |
| `$maxStringLength` | `int` | How many bytes of a string, and of the message, are kept, at least 1 |
| `$dateFormat` | `string` | The format for `DateTimeInterface` values in the context, and for the record time of the formatter that uses it; RFC 3339 with microseconds by default |
| `$redactKeys` | `list<string>` | Keys whose values are written as `[redacted]`, at any depth, ignoring ASCII case |

**Throws:** `InvalidArgumentException` — a limit out of its range, or an empty key in `$redactKeys`.

`normalize()` returns arrays, strings, numbers, booleans and `null` only, with valid UTF-8 everywhere; it never throws for a value. See [Context](/features/context).

`withDateFormat()` returns a normalizer with the same limits and redaction and another date format; a formatter given a `dateFormat` uses it on the normalizer you pass.

`interpolate()` returns the message the built-in formatters write: each `{key}` replaced with the value under `key` when it normalizes to text, a number, a boolean or `null`, an exception as `Class: message`, a redacted key as `[redacted]`; then cut to `$maxStringLength` and made valid UTF-8. It converts only the values the message names. See [Messages](/features/formatters#messages).

`encode()` writes JSON the way the built-in formatters do: one line, slashes and Unicode unescaped, invalid UTF-8 substituted, zero fractions kept. It throws `JsonException` only for data `normalize()` never returns, such as `NAN`.

`validUtf8()` returns the text with every invalid UTF-8 sequence replaced by U+FFFD, for text written outside JSON.
