<?php

declare(strict_types=1);

namespace Solo\Logger\Writer;

/** @internal The write loop the built-in writers share; not part of the public API. */
final class StreamIo
{
    /**
     * Write all of $data, then flush it to the operating system.
     *
     * @param resource $stream
     * @throws WriteException
     */
    public static function writeAll($stream, string $data, string $name): void
    {
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new WriteException(sprintf('Unable to write to "%s".', $name));
            }
            $offset += $written;
        }
        if (!fflush($stream)) {
            throw new WriteException(sprintf('Unable to flush "%s".', $name));
        }
    }
}
