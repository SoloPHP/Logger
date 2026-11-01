# Context

The context of a log call can hold anything: objects with secrets inside, recursive arrays, resources, exceptions with long traces, broken UTF-8. Before formatting, `ContextNormalizer` converts it into plain data — arrays, strings, numbers, booleans and `null` — that is bounded in size, valid UTF-8 and safe to encode as JSON. All three built-in formatters use it.

## Values

| Value | Written as |
|---|---|
| string | The string; invalid UTF-8 replaced with U+FFFD, a long string truncated |
| int, float, bool, null | As is; `NAN`, `INF` and `-INF` as those strings |
| Backed enum | Its value: `'active'` |
| Pure enum | Its name: `'Hearts'` |
| `DateTimeInterface` | Formatted like the record time: the formatter's `dateFormat`, RFC 3339 with microseconds by default |
| `Stringable` | The result of `__toString()` |
| `Throwable` | A description: see [Exceptions](#exceptions) |
| array | Normalized recursively |
| Any other object | A marker: `[object:App\Entity\User]` |
| resource | A marker: `[resource:stream]`, `[resource (closed)]` |

Other objects are never inspected: not their properties, not `jsonSerialize()`, not `__debugInfo()`. Logging `['user' => $user]` writes the class name, not the password hash inside the entity. Pass the fields you need instead: `['user_id' => $user->id]`.

## Exceptions

```php
$logger->error('Import failed', ['exception' => $e]);
```

```json
{
    "exception": {
        "class": "App\\Import\\ImportException",
        "message": "Row 17 is invalid",
        "code": 0,
        "file": "/app/src/Import/Importer.php",
        "line": 42,
        "trace": [
            "/app/src/Import/ImportCommand.php:31 App\\Import\\Importer->import()",
            "/app/bin/console:12 App\\Import\\ImportCommand->run()"
        ],
        "previous": [
            {
                "class": "ValueError",
                "message": "Invalid date \"2026-02-30\"",
                "code": 0,
                "file": "/app/src/Import/Row.php",
                "line": 18,
                "trace": ["…"]
            }
        ]
    }
}
```

Each frame reads `file:line call()` — where the call was made and what was called; frames inside PHP itself read `[internal] call()`. Arguments and objects are never written, also when `zend.exception_ignore_args` is off.

`previous` lists the rest of the exception chain, the direct cause first and the root cause last, each with the same fields but `previous`. A list costs no depth, so a long chain keeps its root cause whatever `maxDepth` is; it is cut only at `maxItems` entries, like the trace.

A placeholder for the exception, `{exception}`, reads `App\Import\ImportException: Row 17 is invalid` in the message; the description above stays in the context.

## Limits

| Limit | Default | Allowed | Marker |
|---|---|---|---|
| `maxDepth` | `8` | 1–64 | `[depth limit]` replaces an array or exception nested deeper |
| `maxItems` | `1000` | 1 or more | `[item limit]` follows the last entry that fits |
| `maxStringLength` | `16384` bytes | 1 or more | `[truncated]` follows a cut string |

- **Depth** counts nesting, with the top-level values at depth 1. Only arrays and exceptions nest: a date or a `Stringable` at the limit is still converted.
- **Items** cap the entries of the whole context. Every array keeps its own entries before its nested values get theirs, so a huge payload or a long trace cannot push out the keys next to it: a `request_id` logged after an exception survives. A trace is also cut to `maxItems` frames.
- **Strings** are cut at a character boundary, never inside a UTF-8 sequence. The message is cut at the same length, once its placeholders are replaced.

```php
use Solo\Logger\Context\ContextNormalizer;
use Solo\Logger\Formatter\JsonFormatter;

$formatter = new JsonFormatter(normalizer: new ContextNormalizer(
    maxDepth: 12,
    maxItems: 2000,
    maxStringLength: 32768,
));
```

## Redaction

Name the keys whose values must never reach the log:

```php
use Solo\Logger\Context\ContextNormalizer;
use Solo\Logger\Formatter\JsonFormatter;

$formatter = new JsonFormatter(normalizer: new ContextNormalizer(
    redactKeys: ['password', 'password_confirmation', 'token', 'authorization', 'cookie'],
));

$logger->info('Login {user} with {password}', [
    'user' => 'ann',
    'password' => 's3cret',
    'headers' => ['Authorization' => 'Bearer eyJhbGciOi…', 'Accept' => 'application/json'],
]);
```

```json
{"time":"2026-11-01T09:30:00.123456+00:00","level":"info","message":"Login ann with [redacted]","context":{"user":"ann","password":"[redacted]","headers":{"Authorization":"[redacted]","Accept":"application/json"}}}
```

- A key matches when it equals a listed one, ignoring the case of ASCII letters; `password_hint` is not `password`. List each variant your application uses.
- Keys match at every depth, and the value under a matching key is neither converted nor read: an array under it is replaced whole.
- A placeholder for a redacted key reads `[redacted]` too.
- The description of an exception is left alone: its `message` and `code` are not user data.
- Values are not searched. A token inside a URL, a password inside an exception message, is written as it is: keep secrets out of strings you log.

Passing a normalizer for its redaction does not change how dates read: a formatter with a `dateFormat` of its own keeps writing every date in it. See [Dates and Timezones](/features/formatters#dates-and-timezones).

## Conversion Failures

`__toString()` is user code, and so is the `format()` of a `DateTime` subclass. When a conversion throws or raises a warning, the value becomes `[unprintable:App\Money\Amount]` and the record is still written. Errors silenced with `@`, or excluded by `error_reporting`, are not failures. The error handler in place before the conversion is restored after it.

## In Your Own Formatter

```php
use Solo\Logger\Context\ContextNormalizer;

$message = $this->normalizer->interpolate($record->message, $record->context);
$context = $this->normalizer->normalize($record->context);
$json = ContextNormalizer::encode(['message' => $message, 'context' => (object) $context]);
```

`interpolate()` returns the message the built-in formatters write: placeholders replaced, cut to `maxStringLength`, valid UTF-8. `ContextNormalizer::encode()` writes JSON the way they do: on one line, with slashes and Unicode unescaped, invalid UTF-8 substituted and zero fractions kept (`1.0`). For other text outside JSON, `ContextNormalizer::validUtf8()` replaces invalid UTF-8 with U+FFFD. See [Custom Formatters](/features/extending#custom-formatters).
