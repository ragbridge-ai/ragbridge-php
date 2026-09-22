# Sync

Keeps the service in step with your own records, by their own external id, through the
Laravel queue or Symfony Messenger. It builds on the client's
[documents by external id](usage.md#keep-documents-in-sync-with-your-records), which need
ragbridge service 1.2.0 or later. The design is recorded in
[ADR 0007](adr/0007-queued-data-sync.md).

A record is reconciled, not replayed: whichever job or message runs last sends the record's
current state, whatever order the queue delivers them in, so running one twice, or out of
order, changes nothing.

## Declaring what to sync

Implement `Ragbridge\Sync\Syncable` on the model or entity:

```php
use Ragbridge\Sync\SyncDocument;
use Ragbridge\Sync\Syncable;

class Article extends Model implements Syncable // or a Doctrine entity
{
    public function toRagbridgeDocument(): ?SyncDocument
    {
        if (! $this->published) {
            return null; // removes the document instead of sending one
        }

        return new SyncDocument($this->title, $this->body, ['category' => $this->category]);
    }
}
```

Returning `null` deletes the document. It is the only thing `toRagbridgeDocument()`
decides; there is no separate "should this be indexed" flag to keep in step with it.

**The external id** defaults to the table name and the primary key, for example `articles:42`.
To choose your own, implement `Ragbridge\Sync\HasExternalId`:

```php
use Ragbridge\Sync\HasExternalId;

public function ragbridgeExternalId(): string
{
    return 'article:' . $this->slug;
}
```

It must stay the same while the record exists. A record whose id changes leaves its old
document behind in the service.

**Removing a record beyond its document**, for example because it should not be visible
right now for a reason that is not about its content, implement
`Ragbridge\Sync\ShouldSyncToRagbridge`:

```php
use Ragbridge\Sync\ShouldSyncToRagbridge;

public function shouldSyncToRagbridge(): bool
{
    return $this->tenant->isActive();
}
```

When this returns `false` the document is deleted and `toRagbridgeDocument()` is not
called. A record without this interface is always eligible.

## Laravel

Add the `Ragbridge\Laravel\Sync\SyncsWithRagbridge` trait to a model that implements
`Syncable`:

```php
use Illuminate\Database\Eloquent\Model;
use Ragbridge\Laravel\Sync\SyncsWithRagbridge;
use Ragbridge\Sync\Syncable;

class Article extends Model implements Syncable
{
    use SyncsWithRagbridge;

    // toRagbridgeDocument() as above
}
```

Saving, deleting or restoring the model queues `Ragbridge\Laravel\Sync\SyncModelJob` after
the database transaction the change was made in commits, so a change that is rolled back is
never sent. The job reloads the model when it runs and sends its state then, which is also
how a model using Eloquent's `SoftDeletes` trait works: the model is hidden by its own
global scope while it is trashed, so the job finds nothing, and the document is deleted the
same way it would be for a hard delete.

To reconcile a record without waiting for the queue, call `queueRagbridgeSync()` directly;
it still checks `ragbridge.sync.enabled` and still dispatches through the queue.

### Backfill

For records that already exist, or that changed through something other than Eloquent, such
as a mass update or an import:

```bash
php artisan ragbridge:sync "App\Models\Article"
```

It walks the table in chunks (`--chunk=200` by default) and queues the same job for each
record.

### Configuration

```dotenv
RAGBRIDGE_SYNC_ENABLED=true
RAGBRIDGE_SYNC_CONNECTION=redis
RAGBRIDGE_SYNC_QUEUE=ragbridge-sync
```

| Variable                    | Default | Meaning                                                    |
| ---------------------------- | ------- | ------------------------------------------------------------ |
| `RAGBRIDGE_SYNC_ENABLED`     | `true`  | Turns the sync off, for tests or maintenance                 |
| `RAGBRIDGE_SYNC_CONNECTION`  | *(unset)* | Queue connection the job is dispatched on; unset uses the application's default |
| `RAGBRIDGE_SYNC_QUEUE`       | *(unset)* | Queue name the job is dispatched on; unset uses the connection's default |

There is no list of synced models in the configuration: implementing `Syncable` is what
opts a model in.

### Failure handling

The job does not call `waitUntilProcessed()`, so it never holds a worker while the service
embeds. A validation error (422), a request that is too large (413) or an id that cannot be
sent fails the job permanently, since sending the same record again cannot help. A
conflict with a concurrent delete (409), an unavailable service (503) and other transient
failures are rethrown, so the queue's own retry and backoff apply.

## Symfony

Both `symfony/messenger` and `doctrine/orm` need to be installed; the bundle registers the
sync services only when both are, and registers nothing otherwise:

```bash
composer require symfony/messenger doctrine/orm doctrine/doctrine-bundle
```

An entity only needs to implement `Syncable`:

```php
use Doctrine\ORM\Mapping as ORM;
use Ragbridge\Sync\SyncDocument;
use Ragbridge\Sync\Syncable;

#[ORM\Entity]
class Article implements Syncable
{
    // toRagbridgeDocument() as above
}
```

`Ragbridge\Symfony\Sync\EntityChangeListener` is registered as a Doctrine event listener.
It collects the entities that changed while the entity manager flushes, and dispatches
`Ragbridge\Symfony\Sync\SyncMessage` for each once the flush succeeds, so an entity that is
never actually persisted is never sent. `Ragbridge\Symfony\Sync\SyncMessageHandler`,
registered on the default bus, reloads the entity when the message is handled and
reconciles it.

Where the message is routed, and whether it is handled asynchronously, is your own
Messenger configuration:

```yaml
framework:
    messenger:
        transports:
            ragbridge: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            'Ragbridge\Symfony\Sync\SyncMessage': ragbridge
```

A message that is not routed to a transport is handled synchronously during the flush, as
Messenger does for any message.

Only an entity with a single-column identifier is supported. One with a composite
identifier needs `Ragbridge\Sync\HasExternalId` for the external id, and still needs a
single-column identifier to be reloaded when a message is handled.

### Backfill

For records that already exist, or that changed through something other than the entity
manager's unit of work:

```bash
bin/console ragbridge:sync "App\Entity\Article"
```

It walks the table in chunks (`--chunk=200` by default, clearing the entity manager between
chunks) and dispatches the same message for each record.

### Configuration

```yaml
ragbridge:
    sync:
        enabled: true
```

| Option    | Default | Meaning                                        |
| --------- | ------- | ------------------------------------------------- |
| `enabled` | `true`  | Turns the sync off, for tests or maintenance       |

There is no list of synced entities in the configuration: implementing `Syncable` is what
opts an entity in. There is no queue connection to configure here either; that is your
Messenger routing, shown above.

### Failure handling

The handler does not call `waitUntilProcessed()`, so it never holds a worker while the
service embeds. A validation error (422), a request that is too large (413) or an id that
cannot be sent is reported as `Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException`,
so Messenger does not retry it. A conflict with a concurrent delete (409), an unavailable
service (503) and other transient failures are left to propagate, so the transport's own
retry strategy applies.

## Testing

Neither integration needs a live service to test against: mock `Ragbridge\RagbridgeClient`,
or the `RagbridgeClient` singleton in the container, the way the rest of your code that
uses the client already does. The job and the message handler only add reconciliation on
top of it, which is `Ragbridge\Sync\Reconciler` and is covered by this package's own tests.
