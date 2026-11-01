<?php

declare(strict_types=1);

namespace Solo\Logger\Rotation;

final readonly class RotationOptions
{
    public function __construct(
        public ?int $maxBytes = null,
        public ?int $intervalSeconds = null,
        public ?Schedule $schedule = null,
        public ?int $maxFiles = null,
    ) {
        $limits = ['maxBytes' => $maxBytes, 'intervalSeconds' => $intervalSeconds, 'maxFiles' => $maxFiles];
        foreach ($limits as $key => $value) {
            if ($value !== null && $value < 1) {
                throw new \InvalidArgumentException($key . ' must be positive, or null to disable the limit.');
            }
        }
        if ($intervalSeconds !== null && $schedule !== null) {
            throw new \InvalidArgumentException('Rotate by intervalSeconds or by a schedule, not both.');
        }
        // Retention runs after a rotation: without a trigger it would never apply.
        if ($maxFiles !== null && $maxBytes === null && $intervalSeconds === null && $schedule === null) {
            throw new \InvalidArgumentException(
                'maxFiles needs maxBytes, intervalSeconds or a schedule to trigger rotation.',
            );
        }
    }
}
