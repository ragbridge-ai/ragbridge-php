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
