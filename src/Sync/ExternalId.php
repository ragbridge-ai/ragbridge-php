<?php

declare(strict_types=1);

namespace Ragbridge\Sync;

/**
 * The default external id of a record.
 */
final class ExternalId
{
    private function __construct() {}

    /**
     * The table name and the primary key, as in `posts:42`.
     */
    public static function of(string $table, int|string $key): string
    {
        return $table . ':' . $key;
    }
}
