<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Laravel\Sync\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Builds and saves a fixture model without going through Eloquent's magic static methods
 * (`create()`, `find()`, ...), which PHPStan cannot see the type of without Larastan.
 */
final class ModelFactory
{
    private function __construct() {}

    /**
     * @template TModel of Model
     *
     * @param class-string<TModel> $class
     * @param array<string, mixed> $attributes
     *
     * @return TModel
     */
    public static function create(string $class, array $attributes): Model
    {
        $model = new $class();
        $model->forceFill($attributes);
        $model->save();

        return $model;
    }

    /**
     * The model's primary key, narrowed to what {@see \Ragbridge\Laravel\Sync\SyncModelJob}
     * accepts.
     */
    public static function key(Model $model): int|string
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key)
            ? $key
            : throw new RuntimeException(sprintf('The primary key of %s must be an int or a string.', $model::class));
    }
}
