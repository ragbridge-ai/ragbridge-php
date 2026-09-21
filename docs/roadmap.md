# Roadmap

## Scope

`ragbridge/php` is a PHP client for the ragbridge service, a self-hosted
retrieval-augmented generation API. The package makes the service's HTTP API easy to use
from plain PHP, Laravel and Symfony applications.

Retrieval, embedding and generation all happen in the service. This package handles
transport, typed responses, error handling and framework wiring.

## Planned

Work is listed in the order it is intended to land. Items describe intent, not commitments
or dates.

1. **Core client.** A framework-independent client for uploading and listing documents,
   deleting documents and querying, with typed response objects and a clear exception
   hierarchy.
2. **Laravel and Symfony integrations.** A service provider and facade for Laravel, and a
   bundle for Symfony, both configured from the application's own configuration.
3. **Queued data sync.** Keep the service in step with application data by indexing
   Eloquent models and Doctrine entities through the framework's queue.
4. **Stable release.** A 1.0 release with a quick start guide, an example application and a
   documented public API.

## Out of scope

- Retrieval, ranking, embedding or generation logic in PHP. These belong to the service
  and stay there.
- Running or managing the service itself.

## Decisions

Architectural decisions are recorded as ADRs in [`docs/adr/`](adr/).
