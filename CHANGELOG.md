# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-09-21

The first stable release. It contains everything from the development milestones 0.1.0 and
0.2.0 below, and defines the public API that semantic versioning covers.

### Added

- Definition of the public API and the versioning policy in the README.
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
- README sections for both integrations and ADR 0005 on keeping them in this package.

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

[Unreleased]: https://github.com/ragbridge-ai/ragbridge-php/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/ragbridge-ai/ragbridge-php/releases/tag/v1.0.0
