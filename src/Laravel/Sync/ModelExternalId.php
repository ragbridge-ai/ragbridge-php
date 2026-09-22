<?php

declare(strict_types=1);

namespace Ragbridge\Laravel\Sync;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Ragbridge\Sync\ExternalId;
use Ragbridge\Sync\HasExternalId;

/**
 * The external id an Eloquent model is synced under: the one it chooses by implementing
 * {@see HasExternalId}, or the table name and the primary key otherwise.
 */
final class ModelExternalId
{
    private function __construct() {}

    /**
     * @throws InvalidArgumentException when the model has neither {@see HasExternalId} nor
     *                                  a primary key that is an int or a string
     */
    public static function of(Model $model): string
    {
        if ($model instanceof HasExternalId) {
            return $model->ragbridgeExternalId();
        }

        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException(sprintf(
                'The primary key of %s must be an int or a string to build a ragbridge external id from it; implement %s to choose the id yourself.',
                $model::class,
                HasExternalId::class,
            ));
        }

        return ExternalId::of($model->getTable(), $key);
    }
}
