<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Fixtures;

final class CountingStringable implements \Stringable
{
    public int $conversions = 0;

    #[\Override]
    public function __toString(): string
    {
        ++$this->conversions;
        return 'converted';
    }
}
