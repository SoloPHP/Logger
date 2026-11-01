<?php

declare(strict_types=1);

namespace Solo\Logger\Formatter;

use Solo\Logger\Context\ContextNormalizer;
use Solo\Logger\LogRecord;

final readonly class LineFormatter implements FormatterInterface
{
    public const string DEFAULT_TEMPLATE = '[{time}] {channel}{level}: {message}{context}';

    private ContextNormalizer $normalizer;
    private bool $writesContext;

    /** @param string|null $dateFormat the format of every date written, the normalizer's when null */
    public function __construct(
        private string $template = self::DEFAULT_TEMPLATE,
        ?string $dateFormat = null,
        ?ContextNormalizer $normalizer = null,
    ) {
        $normalizer ??= new ContextNormalizer();
        $this->normalizer = $dateFormat === null ? $normalizer : $normalizer->withDateFormat($dateFormat);
        // The template and the date format are trusted configuration, written as they are: they must fit on a line.
        if (strpbrk($template . $this->normalizer->dateFormat, "\r\n") !== false) {
            throw new \InvalidArgumentException('The template and the date format must fit on one line.');
        }
        $this->writesContext = str_contains($template, '{context}');
    }

    #[\Override]
    public function format(LogRecord $record): string
    {
        $context = $this->writesContext ? $this->normalizer->normalize($record->context) : [];

        // Everything the record supplies is escaped onto one line.
        // Like {context}, {channel} brings its own separator, so the default line reads the same without one.
        return strtr($this->template, [
            '{time}' => $record->time->format($this->normalizer->dateFormat),
            '{channel}' => $record->channel === null ? '' : self::escape($record->channel) . '.',
            '{level}' => self::escape(strtoupper($record->level)),
            '{message}' => self::escape($this->normalizer->interpolate($record->message, $record->context)),
            '{context}' => $context === [] ? '' : ' ' . ContextNormalizer::encode((object) $context),
        ]);
    }

    /** Valid UTF-8 with control characters escaped: no line breaks, no terminal escape sequences. */
    private static function escape(string $text): string
    {
        return addcslashes(ContextNormalizer::validUtf8($text), "\0..\37\177");
    }
}
