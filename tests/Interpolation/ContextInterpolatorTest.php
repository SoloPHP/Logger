<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Interpolation;

use PHPUnit\Framework\TestCase;
use Solo\Logger\Interpolation\ContextInterpolator;

class ContextInterpolatorTest extends TestCase
{
    public function testNonStringableObjectIsSkipped(): void
    {
        $result = (new ContextInterpolator())->interpolate('Val: {x}', ['x' => new \stdClass()]);

        $this->assertSame('Val: {x}', $result);
    }
}
