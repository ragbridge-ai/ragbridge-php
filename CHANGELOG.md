# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Package skeleton with PSR-4 autoloading and PSR-18/PSR-17 runtime dependencies.
- Tooling: PHPStan (level max, strict rules), Laravel Pint and Pest, exposed as the
  `test`, `stan`, `lint` and `check` composer scripts.
- GitHub Actions workflow running lint, static analysis and tests on PHP 8.2, 8.3 and 8.4.
- Roadmap and architecture decision records.
- Contributing guide, security policy, code of conduct and pull request template.
- `RagbridgeClient` for the ragbridge HTTP API, built with `RagbridgeClient::create()` from
  the installed PSR-18 client and PSR-17 factories, or with the constructor and any
  PSR-18 client. Supports an optional API key sent as a bearer token.
- `query()` with `topK`, `SearchMode` and an `explain` option that adds retrieval details
  to each source.
- `upload()`, `uploadStream()`, `documents()`, `document()` and `deleteDocument()`. Uploads
  are streamed as multipart form data.
- Immutable response objects: `Document`, `DocumentStatus`, `QueryResult`, `Source` and
  `RetrievalInfo`.
- Exceptions implementing `RagbridgeException`: `TransportException`,
  `InvalidResponseException`, `AuthenticationException`, `NotFoundException`,
  `ValidationException` (with `errors()`), `ServerException` and `RequestFailedException`.
- README usage guide and ADR 0004 on typed response objects.
