<?php

declare(strict_types=1);

namespace Solo\Logger\Interpolation;

class ContextInterpolator
{
    /**
     * Interpolate context values into message placeholders
     */
    public function interpolate(string|\Stringable $message, array $context = []): string
    {
        $replace = [];

        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        return strtr((string) $message, $replace);
    }
}
