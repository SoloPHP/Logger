<?php

declare(strict_types=1);

namespace Solo\Logger\Interpolation;

final class ContextInterpolator
{
    /**
     * Interpolate context values into PSR-3 `{placeholder}` tokens in the message.
     * Array and non-stringable object values are skipped.
     *
     * @param array<string, mixed> $context
     */
    public function interpolate(string|\Stringable $message, array $context = []): string
    {
        $replace = [];

        foreach ($context as $key => $val) {
            if (is_array($val)) {
                continue;
            }
            if (is_object($val) && !method_exists($val, '__toString')) {
                continue;
            }
            $replace['{' . $key . '}'] = (string) $val;
        }

        return strtr((string) $message, $replace);
    }
}
