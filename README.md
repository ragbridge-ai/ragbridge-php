# ragbridge/php

[![CI](https://github.com/ragbridge-ai/ragbridge-php/actions/workflows/ci.yml/badge.svg)](https://github.com/ragbridge-ai/ragbridge-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

PHP client for the ragbridge retrieval-augmented generation service.

ragbridge is a self-hosted service that exposes retrieval-augmented generation over an
HTTP API: you upload documents, then ask questions and receive answers together with the
sources they were drawn from. This package lets PHP applications use that API with a few
lines of code, from plain PHP, Laravel or Symfony.

All retrieval, embedding and generation logic lives in the service. This package handles
transport, typed responses, error handling and framework integration only.

## Status

**In development.** The package is not released yet and its API is not stable. See the
[roadmap](docs/roadmap.md) for what is planned.

## Requirements

- PHP 8.2 or later
- A running ragbridge service
- A PSR-18 HTTP client and PSR-17 factories, for example Guzzle, Symfony HttpClient or
  `nyholm/psr7` (most applications already have one; see
  [ADR 0002](docs/adr/0002-psr18-http-client.md))

## Installation

```bash
composer require ragbridge/php
```

The package has not been published to Packagist yet. Until it is, install it from the
repository as a VCS source.

## Usage

Usage documentation will be added together with the client.

## Documentation

- [Roadmap](docs/roadmap.md)
- [Architecture decision records](docs/adr/)
- [Changelog](CHANGELOG.md)

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) first. To report
a security issue, follow [SECURITY.md](SECURITY.md).

## License

Released under the [MIT License](LICENSE).
