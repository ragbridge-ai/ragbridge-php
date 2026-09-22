<?php

declare(strict_types=1);

namespace Ragbridge\Laravel\Sync;

use Illuminate\Support\Facades\Config;

/**
 * Queues {@see SyncModelJob} whenever the model is saved or deleted.
 *
 * Add this trait, and implement {@see \Ragbridge\Sync\Syncable}, on an Eloquent model to
 * keep it in step with the service. Dispatching happens after the database transaction the
 * change was made in commits, so a change that is rolled back is never sent.
 *
 * A model with Eloquent's SoftDeletes trait needs only this trait too: restoring it, like
 * creating and updating it, ends in a `save()` internally, which fires `saved`. There is no
 * separate listener for the `restored` event, so a restore is not queued twice.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 * @phpstan-require-implements \Ragbridge\Sync\Syncable
 */
trait SyncsWithRagbridge
{
    public static function bootSyncsWithRagbridge(): void
    {
        static::saved(static function (self $model): void {
            $model->queueRagbridgeSync();
        });

        static::deleted(static function (self $model): void {
            $model->queueRagbridgeSync();
        });
    }

    /**
     * Queues the job right away, outside the saved/deleted events, for example after a
     * change that does not go through Eloquent, such as a mass update.
     */
    public function queueRagbridgeSync(): void
    {
        if (Config::get('ragbridge.sync.enabled', true) !== true) {
            return;
        }

        SyncModelJob::dispatch(static::class, $this->getKey(), ModelExternalId::of($this))
            ->afterCommit()
            ->onConnection(self::nullableConfigString('ragbridge.sync.connection'))
            ->onQueue(self::nullableConfigString('ragbridge.sync.queue'));
    }

    /**
     * A string configuration value, or null when it is unset, empty or not a string, so the
     * job falls back to the application's default queue connection or queue name.
     */
    private static function nullableConfigString(string $key): ?string
    {
        $value = Config::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
