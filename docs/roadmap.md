# Roadmap

## Scope

`ragbridge/php` is a PHP client for the ragbridge service, a self-hosted
retrieval-augmented generation API. The package makes the service's HTTP API easy to use
from plain PHP, Laravel and Symfony applications.

Retrieval, embedding and generation all happen in the service. This package handles
transport, typed responses, error handling and framework wiring.

## Shipped

- **Core client.** A framework-independent client for uploading, listing, fetching and
  deleting documents and for querying, with typed response objects and an exception
  hierarchy.
- **Laravel and Symfony integrations.** A service provider and facade for Laravel, and a
  bundle for Symfony, both configured from the application's own configuration.
- **Stable release.** Version 1.0 with a quick start guide, an example application and a
  documented public API. See the [changelog](../CHANGELOG.md).
- **The remaining service endpoints.** Client methods for searching without generating an
  answer, for multi-step questions and for the health checks.
- **Retries with backoff.** Optional, configurable retries of requests that fail for
  transient reasons, with exponential backoff and jitter. Off by default, and POST requests
  are only retried when enabled explicitly ([ADR 0006](adr/0006-retry-policy.md)).

## Planned

Work is listed as intent, not as commitments or dates. New features are added in minor
releases, so none of it breaks the 1.0 API.

- **Queued data sync.** Keep the service in step with application data by indexing
  Eloquent models and Doctrine entities through the framework's queue. The application
  decides what text is indexed, and creates, updates and deletes are idempotent.
  *Depends on the service:* this item requires support for external identifiers in the
  service, that is, creating or replacing a document and deleting it by an identifier
  chosen by the application. Work on it starts once a released version of the service
  includes that support.
- **Streaming answers.** Receiving an answer while it is generated. *Depends on the
  service,* which does not offer streaming today.
- **WordPress integration.** Use of the client from WordPress plugins and themes. The core
  has no framework dependency ([ADR 0003](adr/0003-framework-independent-core.md)), so this
  needs configuration and wiring, not changes to the client.

## Out of scope

- Retrieval, ranking, embedding or generation logic in PHP. These belong to the service
  and stay there.
- Running or managing the service itself.

## Decisions

Architectural decisions are recorded as ADRs in [`docs/adr/`](adr/).
