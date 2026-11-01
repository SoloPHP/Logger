<?php

declare(strict_types=1);

namespace Solo\Logger\Writer;

/**
 * Output to a stream opened once, on the first write: php://stderr, php://stdout, a pipe or a device.
 * Each record goes out with its newline in a single write, which a pipe keeps whole up to PIPE_BUF bytes.
 * For regular files, use FileWriter: it locks and rotates them.
 */
final class StreamWriter implements WriterInterface
{
    /** @var resource|null */
    private $stream = null;

    public function __construct(private readonly string $url = 'php://stderr')
    {
        if ($url === '' || str_contains($url, "\0")) {
            throw new \InvalidArgumentException('A stream URL or path is required.');
        }
    }

    #[\Override]
    public function write(string $record): void
    {
        if ($this->stream === null) {
            $stream = @fopen($this->url, 'ab');
            if ($stream === false) {
                throw new WriteException(sprintf('Unable to open "%s".', $this->url));
            }
            $this->stream = $stream;
        }
        StreamIo::writeAll($this->stream, $record . "\n", $this->url);
    }
}
