# Formatters

A formatter turns a record into one line of text without a line terminator; the writer ends it with `\n`. Pass one to the logger:

```php
use Solo\Logger\Formatter\JsonFormatter;
use Solo\Logger\Logger;

$logger = Logger::file('/var/log/app/app.log', formatter: new JsonFormatter());
```

| Formatter | Best for |
|---|---|
| [`LineFormatter`](#lineformatter) (default) | People reading the file: `tail -f`, `less`, `grep` |
| [`JsonFormatter`](#jsonformatter) | Log pipelines: Loki, Elasticsearch, Datadog, CloudWatch, `jq` |
| [`LogfmtFormatter`](#logfmtformatter) | Both: `key="value"` pairs that tools parse and people can read |

All three write the message and convert the context with the same [`ContextNormalizer`](/features/context), so a record reads the same in every format.

## Messages

`{key}` in the message is replaced with the context value under `key` when it [normalizes](/features/context#values) to text or a number: strings, numbers, booleans, `null`, enums, dates, `Stringable` objects. Booleans and `null` are spelled as in JSON — `true`, `false`, `null` — and floats keep their fraction, `1.0`.

```php
$logger->error('{method} {path}: {exception}', [
    'method' => 'GET',
    'path' => '/tasks',
    'exception' => new \RuntimeException('Connection refused'),
]);
// [2026-11-01T09:30:00.123456+00:00] ERROR: GET /tasks: RuntimeException: Connection refused {"method":"GET",…}
```

- An exception reads as its class and message; its full description stays in the context.
- Arrays stay in the context only, and so does a key the message does not name.
- A [redacted](/features/context#redaction) key reads `[redacted]`.
- The message is then cut at the normalizer's `maxStringLength`, 16 KiB by default, and invalid UTF-8 in it becomes U+FFFD.

Only the values the message names are converted for it, so a placeholder costs nothing for the rest of the context.

## LineFormatter

```text
[2026-11-01T09:30:00.123456+00:00] INFO: User 42 signed in {"id":42}
```

```php
use Solo\Logger\Formatter\LineFormatter;

$formatter = new LineFormatter(
    template: '{time} [{level}] {message}{context}',
    dateFormat: 'Y-m-d H:i:s',
);
// 2026-11-01 09:30:00 [INFO] User 42 signed in {"id":42}
```

| Token | Value |
|---|---|
| `{time}` | The record time in the formatter's date format: RFC 3339 with microseconds by default |
| `{channel}` | The logger's [channel](/guide/quick-start#channels) and a dot, `payments.`; nothing without a channel |
| `{level}` | The level in capitals: `INFO` |
| `{message}` | The message with its placeholders replaced |
| `{context}` | A space and the context as JSON; nothing when the context is empty |

The default template is `LineFormatter::DEFAULT_TEMPLATE`: `'[{time}] {channel}{level}: {message}{context}'`. Like `{context}`, `{channel}` brings its own separator, so a logger without a channel writes `[…] INFO: …` and one with a channel `[…] payments.INFO: …`. A template without `{context}` writes messages only — and leaves out exceptions too, which reach the line through the context.

### Escaping

The template and the date format are configuration and are written as they are; both must fit on one line, or the constructor throws `InvalidArgumentException`. Everything the record supplies — the channel, the level and the message — is escaped:

- Control characters become C-style escapes: newline `\n`, tab `\t`, ESC `\033`. A message cannot start a forged log line, nor send terminal escape sequences to whoever runs `tail`.
- Invalid UTF-8 becomes U+FFFD (`�`).

```php
$logger->warning('Login failed for {user}', ['user' => "eve\n[2026-11-01T09:30:00.123456+00:00] INFO: Login succeeded"]);
```

```text
[2026-11-01T09:30:00.123456+00:00] WARNING: Login failed for eve\n[2026-11-01T09:30:00.123456+00:00] INFO: Login succeeded {"user":"eve\n[2026-11-01T09:30:00.123456+00:00] INFO: Login succeeded"}
```

Backslashes are not escaped, so a `\n` in the output stands for a newline and for a backslash followed by `n` alike. The line format is for reading; parse records back from JSON or logfmt.

## JsonFormatter

One JSON object per line (NDJSON) with a fixed schema:

```json
{"time":"2026-11-01T09:30:00.123456+00:00","level":"info","message":"User 42 signed in","context":{"id":42}}
```

| Field | Value |
|---|---|
| `time` | The record time in the formatter's date format: RFC 3339 with microseconds by default |
| `level` | The PSR-3 level, lowercase |
| `channel` | The logger's channel; only when it has one |
| `message` | The message with its placeholders replaced |
| `context` | The normalized context; always an object, also when empty or with numeric keys |

Context keys never collide with the schema: a context key `message` stays `context.message`, and a query reads `context.order_id`. Slashes and Unicode are written unescaped. Invalid UTF-8 becomes U+FFFD without losing the other fields.

```php
use Solo\Logger\Formatter\JsonFormatter;

$formatter = new JsonFormatter(dateFormat: DATE_ATOM); // whole seconds
```

## LogfmtFormatter

```text
time="2026-11-01T09:30:00.123456+00:00" level="info" message="User 42 signed in" context.id="42"
```

- Every value is quoted, with JSON string escaping: `\"`, `\\`, `\n`, `\t`, `\u001b`.
- `channel="payments"` follows the level when the logger has a channel.
- Context keys are prefixed with `context.` and percent-encoded with `rawurlencode()`: `a b` becomes `context.a%20b`. `rawurldecode()` the part after the prefix to get the key back; numeric and empty keys work too.
- A value that is not a string is written as JSON text inside the quotes: `context.ok="true"`, `context.tags="[\"a\",\"b\"]"`.

Reading a record back takes one pattern and `json_decode()`:

```php
preg_match_all('/([^\s=]+)=("(?:[^"\\\\]|\\\\.)*")/', $line, $pairs, PREG_SET_ORDER);

$fields = [];
foreach ($pairs as [, $key, $value]) {
    $fields[$key] = json_decode($value);
}
// ['time' => '2026-11-01T09:30:00.123456+00:00', 'level' => 'info', 'message' => 'User 42 signed in', 'context.id' => '42']
```

## Dates and Timezones

The record time comes from the logger's clock: UTC by default, another zone with `Logger::file(timezone: 'Europe/Kyiv')`. A formatter writes every date in one format, the record time and the dates in the context alike: `ContextNormalizer::DEFAULT_DATE_FORMAT`, `'Y-m-d\TH:i:s.uP'`, unless you choose another.

```php
use Solo\Logger\Formatter\JsonFormatter;

$formatter = new JsonFormatter(dateFormat: DATE_RFC3339_EXTENDED); // milliseconds
```

Without a `dateFormat` of its own, a formatter takes that of the normalizer you pass in. With one, its `dateFormat` wins, so a normalizer passed for its limits or its redaction never changes how dates read:

```php
use Solo\Logger\Context\ContextNormalizer;
use Solo\Logger\Formatter\LineFormatter;

$formatter = new LineFormatter(
    dateFormat: 'Y-m-d H:i:s',
    normalizer: new ContextNormalizer(maxItems: 2000, redactKeys: ['password']),
);
// [2026-11-01 09:30:00] INFO: Due {"due":"2026-12-24 10:00:00"}
```

## Your Own Format

Implement `FormatterInterface`: see [Custom Formatters](/features/extending#custom-formatters).
