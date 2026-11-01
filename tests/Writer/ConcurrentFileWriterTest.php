<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Writer;

use PHPUnit\Framework\Attributes\RequiresFunction;
use Solo\Logger\Tests\Support\TemporaryDirectoryTestCase;

final class ConcurrentFileWriterTest extends TemporaryDirectoryTestCase
{
    #[RequiresFunction('proc_open')]
    public function testConcurrentRotationPreservesEveryRecordExactlyOnce(): void
    {
        $path = $this->directory . '/app.log';
        $workers = 4;
        $records = 100;
        $processes = [];
        try {
            for ($worker = 0; $worker < $workers; ++$worker) {
                $script = dirname(__DIR__) . '/Fixtures/concurrent-writer.php';
                $process = proc_open(
                    [PHP_BINARY, $script, $path, (string) $worker, (string) $records],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            foreach ($processes as [, $pipes]) {
                fwrite($pipes[0], '1');
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
            }
            $deadline = microtime(true) + 15;
            foreach ($processes as [$process, $pipes]) {
                // Only the first status after the exit carries the exit code.
                while (($status = proc_get_status($process))['running']) {
                    self::assertLessThan($deadline, microtime(true), 'Concurrent writer timed out.');
                    usleep(10000);
                }
                self::assertSame(0, $status['exitcode'], stream_get_contents($pipes[2]));
            }
        } finally {
            foreach ($processes as [$process, $pipes]) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
        }

        $written = [];
        foreach ([$path, ...$this->archives($path)] as $file) {
            array_push($written, ...file($file, FILE_IGNORE_NEW_LINES));
        }
        $expected = [];
        for ($worker = 0; $worker < $workers; ++$worker) {
            for ($i = 0; $i < $records; ++$i) {
                $expected[] = $worker . ':' . $i . ':' . str_repeat('x', 64);
            }
        }
        sort($expected);
        sort($written);
        self::assertSame($expected, $written);
    }
}
