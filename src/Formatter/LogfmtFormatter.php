<?php

declare(strict_types=1);

namespace Solo\Logger\Formatter;

use DateTimeInterface;

/**
 * Logfmt formatter — key=value pairs separated by spaces, values with spaces/special chars
 * double-quoted and escaped. Common in Heroku, DataDog, Splunk pipelines.
 *
 * Output:
 *   time=2026-04-19T19:32:04+00:00 level=info message="Job queued" id=3 name=App\\Jobs\\Foo
 */
final class LogfmtFormatter implements FormatterInterface
{
    public function __construct(
        private readonly string $dateFormat = DateTimeInterface::ATOM,
    ) {
    }

    public function format(string $level, string|\Stringable $message, array $context, DateTimeInterface $time): string
    {
        $pairs = [
            'time' => $time->format($this->dateFormat),
            'level' => $level,
            'message' => (string) $message,
        ];
        foreach ($context as $key => $value) {
            if (!is_scalar($value) && !is_null($value)) {
                $value = $value instanceof \Throwable
                    ? $value->getMessage()
                    : (is_object($value) && method_exists($value, '__toString')
                        ? (string) $value
                        : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $pairs[$key] = $value ?? '';
        }

        $parts = [];
        foreach ($pairs as $key => $value) {
            $parts[] = $this->escapeKey($key) . '=' . $this->escapeValue((string) $value);
        }
        return implode(' ', $parts);
    }

    private function escapeKey(string $key): string
    {
        // Keys may not contain spaces or '=' — collapse whitespace to underscore.
        return preg_replace('/[\s=]+/', '_', $key) ?? $key;
    }

    private function escapeValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }
        // Needs quoting when it contains whitespace, quotes, or equals.
        if (preg_match('/[\s"=]/', $value)) {
            $escaped = strtr($value, [
                '\\' => '\\\\',
                '"' => '\\"',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
            ]);
            return '"' . $escaped . '"';
        }
        return $value;
    }
}
