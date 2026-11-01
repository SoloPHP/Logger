<?php

declare(strict_types=1);

use Solo\Logger\Rotation\RotationOptions;
use Solo\Logger\Tests\Support\MutableClock;
use Solo\Logger\Writer\FileWriter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[$script, $path, $worker, $count] = $argv;
$writer = new FileWriter($path, new RotationOptions(maxBytes: 512), new MutableClock());
fread(STDIN, 1);
for ($i = 0; $i < (int) $count; ++$i) {
    $writer->write($worker . ':' . $i . ':' . str_repeat('x', 64));
}
