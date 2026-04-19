<?php

declare(strict_types=1);

namespace Solo\Logger\Formatter;

use DateTimeInterface;
use Solo\Logger\Interpolation\ContextInterpolator;

/**
 * Plain-text line formatter with a configurable template and PSR-3 `{placeholder}` interpolation.
 *
 * Template placeholders resolved by the formatter itself:
 *   {time}     → ISO 8601 timestamp
 *   {level}    → PSR-3 level
 *   {message}  → message (already interpolated against $context)
 *
 * Context keys referenced directly in the message (e.g. `"Hello {name}"` + `['name' => 'World']`)
 * are resolved by `ContextInterpolator` before {message} replacement.
 */
final class LineFormatter implements FormatterInterface
{
    public const DEFAULT_TEMPLATE = '[{time}] {level}: {message}';

    private readonly ContextInterpolator $interpolator;

    public function __construct(
        private readonly string $template = self::DEFAULT_TEMPLATE,
        private readonly string $dateFormat = DateTimeInterface::ATOM,
        ?ContextInterpolator $interpolator = null,
    ) {
        $this->interpolator = $interpolator ?? new ContextInterpolator();
    }

    public function format(string $level, string|\Stringable $message, array $context, DateTimeInterface $time): string
    {
        $interpolated = $this->interpolator->interpolate($message, $context);

        return strtr($this->template, [
            '{time}' => $time->format($this->dateFormat),
            '{level}' => strtoupper($level),
            '{message}' => $interpolated,
        ]);
    }
}
