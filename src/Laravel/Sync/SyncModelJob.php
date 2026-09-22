<?php

declare(strict_types=1);

namespace Ragbridge\Laravel\Sync;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Ragbridge\Sync\Reconciler;
use Ragbridge\Sync\ShouldSyncToRagbridge;
use Ragbridge\Sync\Syncable;
use Ragbridge\Sync\SyncDocument;
use Throwable;

/**
 * Reconciles the document of one Eloquent model with the model's current state.
 *
 * The model is reloaded when the job runs, so whichever job runs last sends the latest
 * state, whatever order the queue delivers them in. The external id is fixed at the time
 * the job was queued, so a hard-deleted record, which can no longer be loaded, is still
 * removed under the right id.
 */
final class SyncModelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param class-string<Model&Syncable> $modelClass
     * @param int|string $modelKey the model's primary key
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly int|string $modelKey,
        public readonly string $externalId,
    ) {}

    public function handle(Reconciler $reconciler): void
    {
        try {
            $reconciler->reconcile($this->externalId, $this->document());
        } catch (Throwable $failure) {
            if (Reconciler::isPermanentFailure($failure)) {
                $this->fail($failure);

                return;
            }

            throw $failure;
        }
    }

    private function document(): ?SyncDocument
    {
        $model = $this->loadModel();

        // A model this cannot find is either gone, or, when it uses Eloquent's SoftDeletes,
        // hidden by that trait's own global scope while it is trashed; both mean the same
        // thing here, so neither needs to be told apart from the other.
        if ($model === null || ! self::isEligible($model)) {
            return null;
        }

        $document = $model->toRagbridgeDocument();

        if ($document === null || $document->sourceUpdatedAt !== null) {
            return $document;
        }

        $updatedAt = $model->getAttribute('updated_at');

        return $updatedAt instanceof DateTimeInterface
            ? $document->withSourceUpdatedAt($updatedAt)
            : $document;
    }

    /**
     * @return (Model&Syncable)|null
     */
    private function loadModel(): ?Model
    {
        return $this->modelClass::query()->find($this->modelKey);
    }

    private static function isEligible(Syncable $model): bool
    {
        return ! $model instanceof ShouldSyncToRagbridge || $model->shouldSyncToRagbridge();
    }
}
