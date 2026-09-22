# 7. Sync models through the queue by reconciling their current state

- Status: Accepted
- Date: 2026-09-22

## Context

Applications keep the records they want to search in their own database, as Eloquent models
or Doctrine entities. The service can hold a copy of each record under an id of the
application's choosing: `putDocument()` creates or replaces it, `deleteByExternalId()`
removes it, and both are idempotent. A record that carries the time it was last changed is
ignored when it is older than the stored copy. These methods shipped in 1.2.0. What is
missing is the part that calls them when a record changes.

Several properties of the problem shape the design:

- A save happens inside a web request or a transaction. Sending the text to the service
  there makes the request as slow as the service, and fails it when the service is down.
  If the transaction is rolled back later, the service holds a record that never existed.
- Queues deliver jobs late, out of order and more than once. A job built from the state of
  a record at the time of the change can overwrite a newer state, or recreate a record that
  was deleted in the meantime.
- The text to index is rarely one column. It is often assembled from several fields and
  relations, which only the application knows how to do.
- Laravel has a queue built in. Symfony applications use Messenger, and Doctrine has no
  queue of its own.
- Applications that adopt the sync already have records, which must be indexed once.

## Decision

**Declaring a model.** A model or entity that is kept in the service implements
`Ragbridge\Sync\Syncable`. Its one method, `toRagbridgeDocument()`, returns a typed
`SyncDocument` (title, content, metadata and the time the record was last changed), or
`null` when the record should not be in the service, for example a draft. Configuration
does not map fields to a document: code can build text from several fields, is checked by
static analysis and changes with the model when it is refactored.

**The external id.** It defaults to the table name and the primary key, as in `posts:42`,
which is stable as long as the table and the key are. A model that needs another id
implements `Ragbridge\Sync\HasExternalId`. The id must not change while the record exists:
a record whose id changes leaves its old document behind in the service.

**Reconciling instead of replaying.** Every change queues the same job, which carries the
class, the primary key and the external id, not the data. When the job runs it loads the
record again. If the record exists and `toRagbridgeDocument()` returns a document, it is
sent with `putDocument()`. If the record is gone, soft deleted or returns `null`, the
document is deleted with `deleteByExternalId()`. Whichever job runs last therefore sends
the latest state, whatever order the jobs arrive in, and running a job twice changes
nothing. Deletes follow from this and need no separate opt-in. The time of the last change
defaults to the model's `updated_at` in Laravel, so the service also ignores an older state
that arrives late.

**When jobs are queued.** In Laravel, a trait registers listeners for the model's `saved`
and `deleted` events, and the job is dispatched after the database transaction commits. In
Symfony, a Doctrine listener collects the changed entities while the entity manager
flushes and dispatches Messenger messages after the flush. The id of a removed entity is
read before it is removed. Where a message is routed, and whether it is handled
asynchronously, is the application's Messenger configuration. An application can also
reconcile a record at once, without the queue.

**Failures.** The framework's queue retries a failed job: Laravel with the job's tries and
backoff, Messenger with the transport's retry strategy. A job fails permanently, without
retries, when repeating it cannot help: when the service rejects the document as invalid
(422) or too large (413), or when the id cannot be used. A conflict with a concurrent
delete (409), an unavailable service (503), server and transport errors are retried. The
job does not wait until the service has processed the text, so it does not hold a worker
while the service embeds. A result of `unchanged` or `stale` is a success.

**Existing records.** A console command, `ragbridge:sync`, in Artisan and in the Symfony
console, walks through the records of a class in chunks and queues the same job for each.

**Configuration.** A switch turns the sync off, for tests and maintenance, and Laravel has
settings for the queue connection and queue name. The list of synced models is not
configured anywhere: implementing `Syncable` is the opt-in. `retry_post` is not involved,
because the sync only sends `PUT` and `DELETE`.

The part that decides what to send, and which failures are permanent, has no framework
dependency and lives in `Ragbridge\Sync`. The Laravel and Symfony parts only load records,
queue jobs and register listeners.

## Consequences

- Saving a model costs one queued job and no HTTP request. The service is updated when a
  worker runs the job, so search results lag behind the database by the queue's delay.
- Changes that do not go through Eloquent events or the Doctrine unit of work, such as mass
  updates in a single query, raw SQL and imports, are not seen. The application runs
  `ragbridge:sync` or reconciles the records itself afterwards.
- A job reads the record from the database when it runs, not when it is queued. Several
  quick saves of the same record can send its final state several times; the service
  answers `unchanged` for the repeats.
- A Doctrine entity without a single-column identifier needs `HasExternalId`, since the
  default id uses one primary key value.
- In Symfony, a message that is not routed to a transport is handled synchronously during
  the flush, as Messenger does for any message. The documentation shows the routing.
- The Symfony integration needs `symfony/messenger` and `doctrine/orm` for the sync; they
  are suggested, not required, and the bundle only registers the sync services when both
  are installed.
- `Syncable`, `HasExternalId`, `SyncDocument`, the job and message classes and the
  configuration keys are public API and follow semantic versioning.
