<?php

declare(strict_types=1);

namespace Ragbridge\Laravel\Sync;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Ragbridge\Sync\Syncable;

/**
 * Queues a sync job for every record of a model.
 *
 * For an application adopting the sync with existing records, and for records changed by
 * something other than Eloquent, such as an import or a mass update, which the
 * {@see SyncsWithRagbridge} trait does not see.
 */
final class SyncCommand extends Command
{
    protected $signature = 'ragbridge:sync
        {model : Fully qualified class name of the Eloquent model}
        {--chunk=200 : Number of records loaded at a time}';

    protected $description = 'Queue a ragbridge sync job for every record of a model';

    public function handle(): int
    {
        $class = $this->argument('model');

        if (! is_string($class) || ! is_a($class, Model::class, true) || ! is_a($class, Syncable::class, true)) {
            $this->components->error(sprintf(
                '%s must be the class name of an Eloquent model implementing %s.',
                is_string($class) ? $class : 'The model',
                Syncable::class,
            ));

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $queued = 0;

        $class::query()->chunkById($chunk, function (iterable $models) use (&$queued): void {
            foreach ($models as $model) {
                SyncModelJob::dispatch($model::class, $model->getKey(), ModelExternalId::of($model));

                $queued++;
            }
        });

        $this->components->info(sprintf('Queued %d record(s) of %s.', $queued, $class));

        return self::SUCCESS;
    }
}
