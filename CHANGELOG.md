# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Queued data sync: keeps the service in step with application data by reconciling
  documents from Eloquent models or Doctrine entities. A model or entity opts in by
  implementing `Ragbridge\Sync\Syncable`; `Ragbridge\Sync\HasExternalId` lets it choose its
  own external id, and `Ragbridge\Sync\ShouldSyncToRagbridge` lets it remove itself from
  the service beyond what its document says. It needs ragbridge service 1.2.0 or later, see
  [Sync](docs/sync.md) and [ADR 0007](docs/adr/0007-queued-data-sync.md).
- In Laravel, the `Ragbridge\Laravel\Sync\SyncsWithRagbridge` trait queues a job after a
  model is saved, deleted or restored, once the enclosing transaction commits. The
  `ragbridge:sync` Artisan command backfills existing records.
- In Symfony, a Doctrine listener dispatches a Messenger message once a flush that changed
  a `Syncable` entity succeeds, and a handler reconciles it. Registered by the bundle only
  when `symfony/messenger` and `doctrine/orm` are both installed; neither is required by
  the package. The `ragbridge:sync` console command backfills existing records.
- `ragbridge.sync` configuration: `enabled`, and, in Laravel, `connection` and `queue` for
  the dispatched job.

## [1.2.0] - 2026-09-22

Adds documents identified by an id of your application, for keeping the service in step with
your records. It needs ragbridge service 1.2.0 or later. Existing code behaves as before.

### Added

- Documents identified by an id of your application, which need ragbridge service 1.2.0 or
  later: `putDocument()` saves the current state of a record, `getByExternalId()`
  fetches it and `deleteByExternalId()` removes it. `putDocument()` returns a
  `SyncedDocument` with a `SyncResult` (created, replaced, updated, unchanged or stale), the
  document and the HTTP status.
- `waitUntilProcessed()`, which waits until a pending document is ready or failed, and the
  `ProcessingTimeoutException` that it raises when the time runs out.
- `Document` has the new properties `externalId`, `metadata`, `sourceUpdatedAt` and
  `updatedAt`. They keep their defaults when the service does not send them.
- `ConflictException` (HTTP 409) and `ServiceUnavailableException` (HTTP 503).

### Changed

- A 409 and a 503 are now reported as `ConflictException` and `ServiceUnavailableException`.
  They extend `RequestFailedException` and `ServerException`, which were thrown for these
  statuses before, so existing `catch` blocks keep working. Those two classes are no longer
  `final`.
- A retry policy also repeats `PUT` requests by default, because `PUT` is idempotent. The
  client did not send any before.

## [1.1.0] - 2026-09-21

Adds the remaining service endpoints and optional retries. There are no breaking changes:
retries are off unless enabled, and existing code behaves as before.

### Added

- `search()`: retrieve the matching chunks without generating an answer. It returns a
  `SearchResult` of `SearchHit` objects, and takes the same `topK`, `mode` and `explain`
  arguments as `query()`.
- `agent()`: answer a question with several searches. It returns an `AgentResult` with the
  answer, the sources and the `AgentStep` objects that show what was searched.
- `health()` and `readiness()`, which return a `HealthStatus`. A service that is not ready
  answers with HTTP 503, reported as a `ServerException`.
- Optional retries with exponential backoff and jitter, off by default. A `RetryPolicy`,
  passed to `RagbridgeClient::create()` or to the constructor, repeats requests after a
  transport error and after HTTP 429, 502, 503 and 504, and follows `Retry-After`. Only GET
  and DELETE are repeated unless `retryPost` is set. Other 4xx errors and HTTP 500 are never
  retried. The pause is an injectable function, so tests do not wait. See ADR 0006.
- Laravel: a `retry` section in `config/ragbridge.php` and the environment variables
  `RAGBRIDGE_RETRY_ENABLED`, `RAGBRIDGE_RETRY_MAX_ATTEMPTS`, `RAGBRIDGE_RETRY_BASE_DELAY_MS`,
  `RAGBRIDGE_RETRY_MAX_DELAY_MS` and `RAGBRIDGE_RETRY_POST`.
- Symfony: a `retry` option in the `ragbridge` configuration (`enabled`, `max_attempts`,
  `base_delay_ms`, `max_delay_ms` and `retry_post`).
- ADR 0006 on the retry policy.

## [1.0.0] - 2026-09-21

The first stable release. It contains everything from the development milestones 0.1.0 and
0.2.0 below, and defines the public API that semantic versioning covers.

### Added

- Definition of the public API and the versioning policy (`docs/versioning.md`).
- Quick start guide (`docs/quickstart.md`) for plain PHP, Laravel and Symfony, and an
  example application (`examples/basic`).
- Support for PHP 8.2, 8.3 and 8.4, Laravel 12 and 13, and Symfony 6.4, 7 and 8, verified in
  CI on every change: against the newest and the oldest allowed dependencies, and with each
  framework integration installed on its own.
- Integration tests that run the client against a real ragbridge service. They are skipped
  unless a service is configured; `tests/Integration/start.sh` starts one with Docker Compose.

## Development history

Versions 0.1.0 and 0.2.0 were development milestones and were not published as separate
releases.

### 0.2.0 - Laravel and Symfony integrations

#### Added

- Laravel integration: `RagbridgeServiceProvider` with publishable `config/ragbridge.php`
  (`RAGBRIDGE_BASE_URL`, `RAGBRIDGE_API_KEY`) and package auto-discovery, and the
  `Ragbridge` facade.
- Symfony integration: `RagbridgeBundle` with a `ragbridge` configuration (`base_url`,
  `api_key`) that registers an autowirable `RagbridgeClient`, using the application's
  `http_client` through `Psr18Client` when `symfony/http-client` is installed.
- Laravel and Symfony guides and ADR 0005 on keeping the integrations in this package.

#### Changed

- `RagbridgeClient` is no longer `final` and no longer a `readonly` class, so that
  applications and the Laravel facade can replace it with a test double. Its state is still
  immutable.
- An empty API key is treated as no key, so an unset environment variable does not send an
  empty bearer token.

### 0.1.0 - Core client

#### Added

- `RagbridgeClient` for the ragbridge HTTP API, built with `RagbridgeClient::create()` from
  the installed PSR-18 client and PSR-17 factories, or with the constructor and any PSR-18
  client. Supports an optional API key sent as a bearer token.
- `query()` with `topK`, `SearchMode` and an `explain` option that adds retrieval details
  to each source.
- `upload()`, `uploadStream()`, `documents()`, `document()` and `deleteDocument()`. Uploads
  are streamed as multipart form data.
- Immutable response objects: `Document`, `DocumentStatus`, `QueryResult`, `Source` and
  `RetrievalInfo`.
- Exceptions implementing `RagbridgeException`: `TransportException`,
  `InvalidResponseException`, `AuthenticationException`, `NotFoundException`,
  `ValidationException` (with `errors()`), `ServerException` and `RequestFailedException`.
- Tooling and project files: PHPStan (level max, strict rules), Laravel Pint, Pest, GitHub
  Actions, roadmap, architecture decision records, contributing guide, security policy and
  code of conduct.

[Unreleased]: https://github.com/ragbridge-ai/ragbridge-php/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/ragbridge-ai/ragbridge-php/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/ragbridge-ai/ragbridge-php/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/ragbridge-ai/ragbridge-php/releases/tag/v1.0.0
