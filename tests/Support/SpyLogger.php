<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Support;

use Psr\Log\AbstractLogger;

/** A PSR-3 logger outside this package: it records each call, then throws the failure it was given, if any. */
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{mixed, string, array<array-key, mixed>}> */
    public array $calls = [];

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->calls[] = [$level, (string) $message, $context];
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
