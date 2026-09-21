<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Support;

use Closure;
use PHPUnit\Framework\Assert;
use Throwable;

final class Thrown
{
    /**
     * Runs the action and returns the exception it throws, typed as the expected class.
     * Works for interfaces as well, which Pest's toThrow() does not.
     *
     * @template T of Throwable
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function by(Closure $action, string $class): Throwable
    {
        try {
            $action();
        } catch (Throwable $e) {
            Assert::assertInstanceOf($class, $e);

            return $e;
        }

        Assert::fail(sprintf('Expected %s to be thrown, but nothing was thrown.', $class));
    }
}
