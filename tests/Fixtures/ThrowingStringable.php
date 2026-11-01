<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Fixtures;

final class ThrowingStringable implements \Stringable
{
    public function __construct(private readonly \Throwable $failure = new \RuntimeException('conversion failed'))
    {
    }

    #[\Override]
    public function __toString(): string
    {
        throw $this->failure;
    }
}
