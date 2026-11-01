<?php

declare(strict_types=1);

namespace Solo\Logger\Formatter;

use Solo\Logger\Context\ContextNormalizer;
use Solo\Logger\LogRecord;

final readonly class JsonFormatter implements FormatterInterface
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
        $fields = ['time' => $record->time->format($this->normalizer->dateFormat), 'level' => $record->level];
        if ($record->channel !== null) {
            $fields['channel'] = $record->channel;
        }
        $fields['message'] = $this->normalizer->interpolate($record->message, $record->context);
        $fields['context'] = (object) $this->normalizer->normalize($record->context);

        return ContextNormalizer::encode($fields);
    }
}
