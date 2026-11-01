<?php

declare(strict_types=1);

namespace Solo\Logger\Writer;

interface WriterInterface
{
    /**
     * Write a formatted record without a trailing newline. The writer owns record framing.
     *
     * @throws WriteException when the destination fails
     */
    public function write(string $record): void;
}
