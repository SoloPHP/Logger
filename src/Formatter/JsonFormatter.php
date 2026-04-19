<?php

declare(strict_types=1);

namespace Solo\Logger\Formatter;

use DateTimeInterface;

/**
 * Structured single-line JSON (NDJSON) formatter — suited for ELK / Loki / Graylog
 * and any log pipeline that parses one JSON object per line.
 *
 * Output shape:
 *   {"time":"2026-04-19T19:32:04+00:00","level":"info","message":"Job queued","id":3, ...}
 *
 * All `$context` keys are merged into the top-level record as flat fields.
 */
final class JsonFormatter implements FormatterInterface
{
    private const RESERVED_KEYS = ['time', 'level', 'message'];

    public function __construct(
        private readonly string $dateFormat = DateTimeInterface::ATOM,
        private readonly int $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ) {
    }

    public function format(string $level, string|\Stringable $message, array $context, DateTimeInterface $time): string
    {
        $record = [
            'time' => $time->format($this->dateFormat),
            'level' => $level,
            'message' => (string) $message,
        ];

        foreach ($context as $key => $value) {
            if (in_array($key, self::RESERVED_KEYS, true)) {
                // Don't overwrite reserved top-level keys — namespace under context.
                $record['context'][$key] = $this->normalize($value);
                continue;
            }
            $record[$key] = $this->normalize($value);
        }

        return $this->safeEncode($record);
    }

    /**
     * JSON-safe value coercion. Order matters:
     *   Throwable chain → structured trace+previous
     *   DateTimeInterface → formatted string (checked before Stringable — a user class
     *     could extend DateTime and add __toString; format string is more useful than default str)
     *   Stringable object → (string) cast
     *   Array → recursively normalized
     *   Scalars/null → as-is
     */
    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \Throwable) {
            return $this->normalizeThrowable($value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format($this->dateFormat);
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }
        if (is_array($value)) {
            return array_map(fn($v) => $this->normalize($v), $value);
        }
        if (is_object($value)) {
            // Plain objects — render a safe marker instead of letting json_encode leak internals.
            return ['__class' => $value::class];
        }
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeThrowable(\Throwable $e): array
    {
        $record = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        if ($e->getPrevious() !== null) {
            $record['previous'] = $this->normalizeThrowable($e->getPrevious());
        }
        return $record;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function safeEncode(array $record): string
    {
        $encoded = json_encode($record, $this->jsonFlags);
        if ($encoded !== false) {
            return $encoded;
        }

        // Recover from malformed UTF-8 / circular refs — never emit an empty log line.
        $fallback = json_encode(
            [
                'time' => $record['time'] ?? '',
                'level' => $record['level'] ?? '',
                'message' => is_string($record['message'] ?? null) ? $record['message'] : '',
                'json_error' => json_last_error_msg(),
            ],
            $this->jsonFlags | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $fallback !== false
            ? $fallback
            : '{"json_error":"unrecoverable"}';
    }
}
