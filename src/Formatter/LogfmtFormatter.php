<?php

declare(strict_types=1);

namespace Solo\Logger\Formatter;

use Solo\Logger\Context\ContextNormalizer;
use Solo\Logger\LogRecord;

final readonly class LogfmtFormatter implements FormatterInterface
{
    private ContextNormalizer $normalizer;

    /** @param string|null $dateFormat the format of every date written, the normalizer's when null */
    public function __construct(?string $dateFormat = null, ?ContextNormalizer $normalizer = null)
    {
        $normalizer ??= new ContextNormalizer();
        $this->normalizer = $dateFormat === null ? $normalizer : $normalizer->withDateFormat($dateFormat);
    }

    #[\Override]
    public function format(LogRecord $record): string
    {
        $pairs = ['time' => $record->time->format($this->normalizer->dateFormat), 'level' => $record->level];
        if ($record->channel !== null) {
            $pairs['channel'] = $record->channel;
        }
        $pairs['message'] = $this->normalizer->interpolate($record->message, $record->context);
        foreach ($this->normalizer->normalize($record->context) as $key => $value) {
            // Percent encoding is reversible, including '%' itself, and accepts numeric PHP keys.
            $pairs['context.' . rawurlencode((string) $key)] = is_string($value)
                ? $value
                : ContextNormalizer::encode($value);
        }

        $parts = [];
        foreach ($pairs as $key => $value) {
            // JSON string escaping is compatible with quoted logfmt values; quote every value.
            $parts[] = $key . '=' . ContextNormalizer::encode($value);
        }
        return implode(' ', $parts);
    }
}
