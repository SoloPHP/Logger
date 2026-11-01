# Extending

The logger filters levels, reads the clock and builds a `LogRecord`; a formatter turns the record into a line; a writer puts the line somewhere. Each part is an interface, and the logger itself is a PSR-3 `LoggerInterface` that [decorators](/features/decorators) can wrap.

```php
use Psr\Log\LogLevel;
use Solo\Logger\Logger;

$logger = new Logger(
    writer: $writer,         // WriterInterface
    formatter: $formatter,   // FormatterInterface, LineFormatter by default
    minLevel: LogLevel::INFO,
    clock: $clock,           // Psr\Clock\ClockInterface, UTC system time by default
    channel: 'app',          // optional
);
```

The record is formatted before the writer is called, so a formatter that throws leaves no trace in the file system.

## Custom Formatters

Implement `Solo\Logger\Formatter\FormatterInterface`:

```php
interface FormatterInterface
{
    /** Format one record without a trailing newline. */
    public function format(LogRecord $record): string;
}
```

`LogRecord` carries the `level`, the `message` (already a string), the `context` exactly as it was passed, the `time` as a `DateTimeImmutable` and the `channel`, or `null`. Run the message and the context through a [`ContextNormalizer`](/features/context) before encoding them, and return the record without a trailing newline: the writer adds the line break. Keep the record on one line if anything reads the file line by line: `FileWriter` writes a record as it is, so a line break inside it splits the record over several lines.

```php
use Solo\Logger\Context\ContextNormalizer;
use Solo\Logger\Formatter\FormatterInterface;
use Solo\Logger\LogRecord;

final readonly class PipelineFormatter implements FormatterInterface
{
    public function __construct(private ContextNormalizer $normalizer = new ContextNormalizer())
    {
    }

    #[\Override]
    public function format(LogRecord $record): string
    {
        return ContextNormalizer::encode([
            '@timestamp' => $record->time->format('Y-m-d\TH:i:s.vP'),
            'severity' => strtoupper($record->level),
            'logger' => $record->channel ?? 'app',
            'message' => $this->normalizer->interpolate($record->message, $record->context),
            'fields' => (object) $this->normalizer->normalize($record->context),
        ]);
    }
}
```

## Custom Writers

Implement `Solo\Logger\Writer\WriterInterface`:

```php
interface WriterInterface
{
    /**
     * Write a formatted record without a trailing newline. The writer owns record framing.
     *
     * @throws WriteException when the destination fails
     */
    public function write(string $record): void;
}
```

A writer receives one formatted record per call and owns its framing — a newline, a length prefix, a datagram. Throw `Solo\Logger\Writer\WriteException` when the destination fails, so that callers can tell output failures from bugs. The package ships two: [`FileWriter`](/features/rotation) for files and [`StreamWriter`](/features/streams) for standard error and other streams.

### In Tests

```php
use Solo\Logger\Writer\WriterInterface;

final class MemoryWriter implements WriterInterface
{
    /** @var list<string> */
    public array $records = [];

    #[\Override]
    public function write(string $record): void
    {
        $this->records[] = $record;
    }
}
```

```php
$writer = new MemoryWriter();
$logger = new Logger($writer, new JsonFormatter(), clock: new FrozenClock(new \DateTimeImmutable('2026-11-01T09:30:00Z')));

$service = new CheckoutService($logger);
$service->checkout($cart);

self::assertSame('error', json_decode($writer->records[0], true)['level']);
```

## Clocks

Any PSR-20 clock works, for example a frozen one in tests:

```php
use Psr\Clock\ClockInterface;

final readonly class FrozenClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
```

The logger stamps records with its clock; `FileWriter` reads its own for time rotation and archive names. `Logger::file()` gives both one clock. When you build the parts yourself, do the same:

```php
use Psr\Log\LogLevel;
use Solo\Logger\Clock\SystemClock;
use Solo\Logger\Formatter\JsonFormatter;
use Solo\Logger\Logger;
use Solo\Logger\Rotation\RotationOptions;
use Solo\Logger\Writer\FileWriter;

$clock = new SystemClock(new \DateTimeZone('Europe/Kyiv'));

$logger = new Logger(
    new FileWriter('/var/log/app/app.log', new RotationOptions(maxBytes: 10 * 1024 * 1024, maxFiles: 14), $clock),
    new JsonFormatter(),
    LogLevel::INFO,
    $clock,
);
```

## Decorators

`Logger` is final: change what it does by wrapping it. The package ships decorators for several destinations, fail-safe logging and shared context, and shows how to write your own: see [Decorators](/features/decorators).
