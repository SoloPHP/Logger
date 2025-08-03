<?php

declare(strict_types=1);

namespace Solo\Logger\Tests\Interpolation;

use PHPUnit\Framework\TestCase;
use Solo\Logger\Interpolation\ContextInterpolator;

class ContextInterpolatorTest extends TestCase
{
    private ContextInterpolator $interpolator;

    protected function setUp(): void
    {
        $this->interpolator = new ContextInterpolator();
    }

    public function testBasicInterpolation(): void
    {
        $message = 'User {user_id} performed {action}';
        $context = ['user_id' => 123, 'action' => 'login'];

        $result = $this->interpolator->interpolate($message, $context);

        $this->assertEquals('User 123 performed login', $result);
    }

    public function testNoPlaceholders(): void
    {
        $message = 'Simple message without placeholders';
        $context = ['key' => 'value'];

        $result = $this->interpolator->interpolate($message, $context);

        $this->assertEquals('Simple message without placeholders', $result);
    }

    public function testEmptyContext(): void
    {
        $message = 'Message with {placeholder}';
        $context = [];

        $result = $this->interpolator->interpolate($message, $context);

        $this->assertEquals('Message with {placeholder}', $result);
    }

    public function testStringableObject(): void
    {
        $stringableObject = new class implements \Stringable {
            public function __toString(): string
            {
                return 'Stringable content';
            }
        };

        $result = $this->interpolator->interpolate($stringableObject, []);

        $this->assertEquals('Stringable content', $result);
    }

    public function testComplexContext(): void
    {
        $message = 'User {name} (ID: {id}) has {count} items';
        $context = [
            'name' => 'John Doe',
            'id' => 456,
            'count' => 5
        ];

        $result = $this->interpolator->interpolate($message, $context);

        $this->assertEquals('User John Doe (ID: 456) has 5 items', $result);
    }

    public function testArrayInContext(): void
    {
        $message = 'User {name} has roles: {roles}';
        $context = [
            'name' => 'Admin',
            'roles' => ['admin', 'user']
        ];

        $result = $this->interpolator->interpolate($message, $context);

        // Arrays should not be interpolated
        $this->assertEquals('User Admin has roles: {roles}', $result);
    }
}
