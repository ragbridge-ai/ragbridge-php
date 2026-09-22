# ragbridge/php

[![CI](https://github.com/ragbridge-ai/ragbridge-php/actions/workflows/ci.yml/badge.svg)](https://github.com/ragbridge-ai/ragbridge-php/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/ragbridge/php)](https://packagist.org/packages/ragbridge/php)
[![PHP version](https://img.shields.io/packagist/php-v/ragbridge/php)](https://packagist.org/packages/ragbridge/php)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

PHP client for [ragbridge](https://github.com/ragbridge-ai/ragbridge), a self-hosted
retrieval-augmented generation (RAG) service. Upload documents, ask questions in natural
language and get answers together with the sources they came from, from plain PHP, Laravel
or Symfony.

The package is a small, typed client for the service's HTTP API. Retrieval, embedding and
generation all happen in the service.

## Features

- Upload PDF, Markdown and plain-text documents, streamed from disk, and list, fetch and
  delete them
- Ask questions and get an answer with its sources; choose the retrieval mode and the number
  of chunks, and optionally see why each chunk was found
- Search without generating an answer, ask multi-step questions with the agent, and check
  that the service is alive and ready
- Optional retries with exponential backoff for transient failures, off by default
- Typed, immutable response objects instead of arrays
- One exception hierarchy for authentication, validation, transport and server errors
- Works with any PSR-18 HTTP client, with no hard dependency on Guzzle
- Laravel service provider, configuration and facade, and a Symfony bundle

## Requirements

- PHP 8.2 or later
- A running ragbridge service and an API key for it (see the [quick start](docs/quickstart.md)).
  Documents identified by an id of your application need service 1.2.0 or later.
- A PSR-18 HTTP client and PSR-17 factories, for example Guzzle or Symfony HttpClient with
  `nyholm/psr7`. Laravel already includes one.

## Installation

```bash
composer require ragbridge/php
```

If your project has no HTTP client yet, install one as well, for example
`composer require guzzlehttp/guzzle`. The package finds the client that is installed. For
Laravel and Symfony, follow the framework guides below.

## Documentation

| Guide | What it covers |
| ----- | -------------- |
| [Quick start](docs/quickstart.md) | From starting the service to a first answer, in plain PHP, Laravel and Symfony |
| [Usage](docs/usage.md) | Creating a client, uploading documents, asking questions and handling errors |
| [Laravel](docs/laravel.md) | Installation, configuration, facade and testing |
| [Symfony](docs/symfony.md) | Bundle setup, configuration and the HTTP client |
| [Sync](docs/sync.md) | Keeping Eloquent models or Doctrine entities in step with the service through a queue |
| [Example application](examples/) | A small upload-and-ask app to run and read |
| [Versioning](docs/versioning.md) | The public API that semantic versioning covers |
| [Roadmap](docs/roadmap.md), [Changelog](CHANGELOG.md), [Decisions](docs/adr/) | Where the project is going, what changed, and why |

## Supported versions

| Component | Versions |
| --------- | -------- |
| PHP | 8.2, 8.3, 8.4 |
| Laravel | 12, 13 |
| Symfony | 6.4, 7, 8 |

All of them are tested in CI on every change.

## Versioning

The package follows [semantic versioning](https://semver.org/spec/v2.0.0.html). What counts
as public API is defined in [docs/versioning.md](docs/versioning.md).

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) first. To report a
security issue, follow [SECURITY.md](SECURITY.md).

## License

Released under the [MIT License](LICENSE).
