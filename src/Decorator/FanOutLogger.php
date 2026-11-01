<?php

declare(strict_types=1);

namespace Solo\Logger\Decorator;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Solo\Logger\LevelAwareLoggerInterface;
use Solo\Logger\Levels;
use Solo\Logger\Writer\WriteException;

/**
 * Sends every record to several loggers, each with its own destination, format and threshold.
 *
 * A failing logger does not keep the record from the others: all of them are called, then the first failure is
 * rethrown. A bug wins over an output failure, so a FailSafeLogger around this one cannot hide it.
 */
final class FanOutLogger extends AbstractLogger implements LevelAwareLoggerInterface
{
    /** @var list<LoggerInterface> */
    private readonly array $loggers;

    public function __construct(LoggerInterface ...$loggers)
    {
        $this->loggers = array_values($loggers);
    }

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $failure = null;
        foreach ($this->loggers as $logger) {
            try {
                $logger->log($level, $message, $context);
            } catch (\Throwable $e) {
                if ($failure === null || ($failure instanceof WriteException && !$e instanceof WriteException)) {
                    $failure = $e;
                }
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** Whether any of the loggers would write the level; one that cannot tell counts as writing it. */
    #[\Override]
    public function isHandling(string $level): bool
    {
        Levels::assertValid($level);
        foreach ($this->loggers as $logger) {
            if (!$logger instanceof LevelAwareLoggerInterface || $logger->isHandling($level)) {
                return true;
            }
        }
        return false;
    }
}
