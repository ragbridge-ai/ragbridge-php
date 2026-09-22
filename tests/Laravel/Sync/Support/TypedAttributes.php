<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Laravel\Sync\Support;

use RuntimeException;

/**
 * Reads an Eloquent attribute as a definite type, since PHPStan cannot see through
 * Eloquent's magic attribute access without Larastan.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait TypedAttributes
{
    private function stringAttribute(string $key): string
    {
        $value = $this->getAttribute($key);

        return is_string($value)
            ? $value
            : throw new RuntimeException(sprintf('The "%s" attribute of %s must be a string.', $key, static::class));
    }

    private function scalarKey(): int|string
    {
        $key = $this->getKey();

        return is_int($key) || is_string($key)
            ? $key
            : throw new RuntimeException(sprintf('The primary key of %s must be an int or a string.', static::class));
    }
}
