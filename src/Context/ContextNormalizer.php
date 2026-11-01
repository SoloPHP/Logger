<?php

declare(strict_types=1);

namespace Solo\Logger\Context;

/**
 * Bounded conversion to JSON-safe data, shared by all built-in formatters.
 *
 * The result holds only arrays, scalars and null, and every string in it is valid UTF-8. The only user code
 * it runs is string conversion (__toString(), an overridden format()); a failing conversion becomes a marker.
 */
final readonly class ContextNormalizer
{
    /** RFC 3339 with microseconds: the default for record times and for dates in the context. */
    public const string DEFAULT_DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    /** @var array<array-key, true> the redacted keys, lowercase */
    private array $redacted;

    /** @param list<string> $redactKeys keys whose values are never written, matched ignoring ASCII case */
    public function __construct(
        private int $maxDepth = 8,
        private int $maxItems = 1000,
        private int $maxStringLength = 16384,
        public string $dateFormat = self::DEFAULT_DATE_FORMAT,
        array $redactKeys = [],
    ) {
        if ($maxDepth < 1 || $maxDepth > 64 || $maxItems < 1 || $maxStringLength < 1) {
            throw new \InvalidArgumentException('Depth must be 1..64; item and string limits must be positive.');
        }
        $redacted = [];
        foreach ($redactKeys as $key) {
            if ($key === '') {
                throw new \InvalidArgumentException('Redacted keys must not be empty.');
            }
            $redacted[strtolower($key)] = true;
        }
        $this->redacted = $redacted;
    }

    /** The same limits and redaction, with dates in another format. */
    public function withDateFormat(string $dateFormat): self
    {
        $redactKeys = array_map(strval(...), array_keys($this->redacted));
        return new self($this->maxDepth, $this->maxItems, $this->maxStringLength, $dateFormat, $redactKeys);
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    public function normalize(array $context): array
    {
        $remaining = $this->maxItems;
        return $this->normalizeArray($context, 0, $remaining, true);
    }

    /**
     * The message the built-in formatters write: placeholders replaced, cut to the string limit, valid UTF-8.
     *
     * {key} takes the value under key when it normalizes to text, a number, a boolean or null; an exception reads
     * as "Class: message". Arrays stay in the context only. Only the values the message names are converted.
     *
     * @param array<array-key, mixed> $context the context as it was passed, not normalized
     */
    public function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{') || (int) preg_match_all('/\{([^{}]+)\}/', $message, $matches) === 0) {
            return $this->normalizeString($message);
        }
        $replace = [];
        foreach (array_unique($matches[1]) as $name) {
            if (!array_key_exists($name, $context) || is_array($context[$name])) {
                continue;
            }
            $value = $context[$name];
            $text = match (true) {
                $this->isRedacted($name) => '[redacted]',
                $value instanceof \Throwable => $this->normalizeString(
                    get_debug_type($value) . ': ' . $value->getMessage(),
                ),
                default => $this->normalizeLeaf($value),
            };
            $replace['{' . $name . '}'] = is_string($text) ? $text : self::encode($text);
        }
        return $this->normalizeString(strtr($message, $replace));
    }

    /** JSON the way the built-in formatters write it: one line, slashes and Unicode unescaped, 1.0 kept. */
    public static function encode(mixed $data): string
    {
        return json_encode($data, self::JSON_FLAGS);
    }

    /** The text with every invalid UTF-8 sequence replaced by U+FFFD. */
    public static function validUtf8(string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }
        $valid = json_decode(
            json_encode($text, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            flags: JSON_THROW_ON_ERROR,
        );
        assert(is_string($valid));
        return $valid;
    }

    /**
     * @param array<array-key, mixed> $values
     * @param bool $redact false inside an exception description, which is not user data
     * @return array<array-key, mixed>
     */
    private function normalizeArray(array $values, int $depth, int &$remaining, bool $redact): array
    {
        // Reserve the direct entries before descending, so one large value cannot crowd out its siblings.
        $kept = min(count($values), $remaining);
        $remaining -= $kept;
        $redact = $redact && $this->redacted !== [];
        $result = [];
        foreach ($values as $key => $value) {
            if (count($result) === $kept) {
                $result[$key] = '[item limit]';
                break;
            }
            $result[$key] = match (true) {
                $redact && $this->isRedacted($key) => '[redacted]',
                // The commonest values skip the calls.
                $value === null, is_int($value), is_bool($value) => $value,
                default => $this->normalizeValue($value, $depth + 1, $remaining, $redact),
            };
        }
        return $result;
    }

    private function normalizeValue(mixed $value, int $depth, int &$remaining, bool $redact): mixed
    {
        if (!is_array($value) && !$value instanceof \Throwable) {
            return $this->normalizeLeaf($value);
        }
        if ($depth >= $this->maxDepth) {
            return '[depth limit]';
        }
        return is_array($value)
            ? $this->normalizeArray($value, $depth, $remaining, $redact)
            : $this->normalizeArray($this->describe($value), $depth, $remaining, false);
    }

    /** A value that does not nest: anything but an array or an exception. */
    private function normalizeLeaf(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->normalizeString($value);
        }
        if (is_float($value) && !is_finite($value)) {
            // JSON has no such numbers; (string) NAN raises a warning since PHP 8.5.
            return is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF');
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }
        if ($value instanceof \DateTimeInterface || $value instanceof \Stringable) {
            return $this->stringify($value);
        }
        if (is_object($value)) {
            // Do not expose object internals or invoke arbitrary JsonSerializable callbacks.
            return '[object:' . get_debug_type($value) . ']';
        }
        return is_resource($value)
            ? '[resource:' . get_resource_type($value) . ']'
            : '[' . get_debug_type($value) . ']';
    }

    private function isRedacted(int|string $key): bool
    {
        return isset($this->redacted[is_int($key) ? $key : strtolower($key)]);
    }

    /** @return array<string, mixed> */
    private function describe(\Throwable $exception): array
    {
        $description = $this->summarize($exception);
        // The chain as a list, the root cause last: a list costs no depth, so a long chain keeps its root cause.
        $chain = [];
        for ($previous = $exception->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            if (count($chain) === $this->maxItems) {
                break;
            }
            $chain[] = $this->summarize($previous);
        }
        if ($chain !== []) {
            $description['previous'] = $chain;
        }
        return $description;
    }

    /** @return array<string, mixed> */
    private function summarize(\Throwable $exception): array
    {
        // A frame names the call and where it was made, never its arguments or object.
        $trace = [];
        foreach (array_slice($exception->getTrace(), 0, $this->maxItems) as $frame) {
            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()';
            $trace[] = isset($frame['file'])
                ? $frame['file'] . ':' . ($frame['line'] ?? 0) . ' ' . $call
                : '[internal] ' . $call;
        }
        return [
            'class' => get_debug_type($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $trace,
        ];
    }

    private function stringify(\DateTimeInterface|\Stringable $value): string
    {
        // User-defined conversions may throw or emit warnings. Neither should break a log call.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false; // Silenced with @ or excluded by error_reporting: not a failure.
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $text = $value instanceof \DateTimeInterface ? $value->format($this->dateFormat) : (string) $value;
        } catch (\Throwable) {
            return '[unprintable:' . get_debug_type($value) . ']';
        } finally {
            restore_error_handler();
        }
        return $this->normalizeString($text);
    }

    private function normalizeString(string $value): string
    {
        if (strlen($value) > $this->maxStringLength) {
            // Cut before UTF-8 continuation bytes (a character has at most three), never inside a character.
            $length = $this->maxStringLength;
            for ($i = 0; $i < 3 && $length > 0 && (ord($value[$length]) & 0xC0) === 0x80; ++$i) {
                --$length;
            }
            $value = substr($value, 0, $length) . '[truncated]';
        }
        return self::validUtf8($value);
    }
}
