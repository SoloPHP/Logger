<?php

declare(strict_types=1);

namespace Solo\Logger;

final readonly class LogRecord
{
    /** @param array<array-key, mixed> $context */
    public function __construct(
        public string $level,
        public string $message,
        public array $context,
        public \DateTimeImmutable $time,
        public ?string $channel = null,
    ) {
    }
}
