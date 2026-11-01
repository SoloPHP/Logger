<?php

declare(strict_types=1);

namespace Solo\Logger\Decorator;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Solo\Logger\LevelAwareLoggerInterface;
use Solo\Logger\Levels;
use Solo\Logger\Writer\WriteException;

/**
 * Keeps output failures away from the caller: a WriteException from the wrapped logger is reported, not thrown.
 * Anything else, such as an invalid level or a broken formatter, is a bug and still throws.
 */
final class FailSafeLogger extends AbstractLogger implements LevelAwareLoggerInterface
{
    /** @var \Closure(WriteException): void */
    private readonly \Closure $onFailure;

    /** @param (\Closure(WriteException): void)|null $onFailure receives each failure; error_log() by default */
    public function __construct(private readonly LoggerInterface $inner, ?\Closure $onFailure = null)
    {
        $this->onFailure = $onFailure ?? static function (WriteException $failure): void {
            error_log('Logging failed: ' . $failure->getMessage());
        };
    }

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        try {
            $this->inner->log($level, $message, $context);
            // @phpstan-ignore catch.neverThrown (PSR-3 declares only InvalidArgumentException; writers fail too)
        } catch (WriteException $failure) {
            ($this->onFailure)($failure);
        }
    }

    /** Whether the wrapped logger would write the level; one that cannot tell counts as writing it. */
    #[\Override]
    public function isHandling(string $level): bool
    {
        Levels::assertValid($level);
        return !$this->inner instanceof LevelAwareLoggerInterface || $this->inner->isHandling($level);
    }
}
