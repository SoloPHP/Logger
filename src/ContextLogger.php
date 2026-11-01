<?php

declare(strict_types=1);

namespace Solo\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/** Adds the same context, such as a request id or a worker name, to every record; a key passed to a call wins. */
final class ContextLogger extends AbstractLogger implements LevelAwareLoggerInterface
{
    /** @param array<array-key, mixed> $context */
    public function __construct(private readonly LoggerInterface $inner, private readonly array $context)
    {
    }

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        // The shared keys come first in every record, whatever the call passes; nothing to copy without it.
        $context = $context === [] ? $this->context : array_replace($this->context, $context);
        $this->inner->log($level, $message, $context);
    }

    /** Whether the wrapped logger would write the level; one that cannot tell counts as writing it. */
    #[\Override]
    public function isHandling(string $level): bool
    {
        Levels::assertValid($level);
        return !$this->inner instanceof LevelAwareLoggerInterface || $this->inner->isHandling($level);
    }
}
