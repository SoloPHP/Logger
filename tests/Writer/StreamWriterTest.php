<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Writer;

use PHPUnit\Framework\Attributes\DataProvider;
use Solo\Logger\Tests\Support\TemporaryDirectoryTestCase;
use Solo\Logger\Writer\StreamWriter;
use Solo\Logger\Writer\WriteException;

final class StreamWriterTest extends TemporaryDirectoryTestCase
{
    public function testEachRecordEndsWithANewline(): void
    {
        $path = $this->directory . '/out.log';
        $writer = new StreamWriter($path);
        $writer->write('first');
        $writer->write("second\tline");
        self::assertSame("first\nsecond\tline\n", file_get_contents($path));
    }

    public function testStreamOpensOnTheFirstWrite(): void
    {
        $path = $this->directory . '/missing/out.log';
        $writer = new StreamWriter($path);
        self::assertFileDoesNotExist($path);
        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Unable to open "' . $path . '"');
        $writer->write('record');
    }

    public function testStreamIsOpenedOnceAndKept(): void
    {
        self::skipOnWindows('Windows cannot delete a file that is open.');
        $path = $this->directory . '/out.log';
        $writer = new StreamWriter($path);
        $writer->write('first');
        unlink($path);
        $writer->write('second');
        self::assertFileDoesNotExist($path);
    }

    public function testWriteFailureIsReported(): void
    {
        if (!is_writable('/dev/full')) {
            self::markTestSkipped('Needs /dev/full, which rejects every write.');
        }
        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Unable to write to "/dev/full"');
        (new StreamWriter('/dev/full'))->write('record');
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlsAreRejected(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StreamWriter($url);
    }

    public static function invalidUrls(): array
    {
        return [[''], ["php://std\0err"]];
    }
}
