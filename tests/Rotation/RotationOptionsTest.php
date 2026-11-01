<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Rotation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Logger\Rotation\RotationOptions;
use Solo\Logger\Rotation\Schedule;

final class RotationOptionsTest extends TestCase
{
    #[DataProvider('invalidOptions')]
    public function testNonPositiveLimitsAreRejected(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RotationOptions(...$arguments);
    }

    public static function invalidOptions(): array
    {
        return [
            [['maxBytes' => 0]], [['maxBytes' => -1]],
            [['intervalSeconds' => 0]], [['intervalSeconds' => -1]],
            [['maxBytes' => 1, 'maxFiles' => 0]], [['intervalSeconds' => 1, 'maxFiles' => -1]],
        ];
    }

    public function testRetentionWithoutARotationTriggerIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxFiles needs maxBytes, intervalSeconds or a schedule');
        new RotationOptions(maxFiles: 14);
    }

    public function testRetentionAcceptsAnyTrigger(): void
    {
        self::assertSame(14, (new RotationOptions(maxBytes: 1024, maxFiles: 14))->maxFiles);
        self::assertSame(14, (new RotationOptions(intervalSeconds: 3600, maxFiles: 14))->maxFiles);
        self::assertSame(14, (new RotationOptions(schedule: Schedule::Daily, maxFiles: 14))->maxFiles);
    }

    public function testIntervalAndScheduleExcludeEachOther(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rotate by intervalSeconds or by a schedule, not both.');
        new RotationOptions(intervalSeconds: 86400, schedule: Schedule::Daily);
    }
}
