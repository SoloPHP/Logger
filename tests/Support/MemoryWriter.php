<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Support;

use Solo\Logger\Writer\WriterInterface;

final class MemoryWriter implements WriterInterface
{
    /** @var list<string> */
    public array $records = [];

    #[\Override]
    public function write(string $record): void
    {
        $this->records[] = $record;
    }
}
