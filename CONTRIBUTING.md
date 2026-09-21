# Contributing

Thank you for your interest in improving `ragbridge/php`. This document explains how to
set up the project, which checks a change has to pass and how decisions are recorded.

By participating you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Development setup

Requirements: PHP 8.2 or later and [Composer](https://getcomposer.org/) 2.

```bash
git clone git@github.com:ragbridge-ai/ragbridge-php.git
cd ragbridge-php
composer install
```

## Checks

Every change must pass all three checks. CI runs them on PHP 8.2, 8.3 and 8.4.

| Command             | What it does                                           |
| ------------------- | ------------------------------------------------------ |
| `composer test`     | Runs the Pest test suite                               |
| `composer stan`     | Runs PHPStan at level `max` with the strict-rules rules |
| `composer lint`     | Checks code style with Laravel Pint (no files changed) |
| `composer check`    | Runs lint, stan and test                               |

To fix style issues automatically, run `vendor/bin/pint`.

Tests must not call a live service. Use the mock HTTP client and the JSON fixtures in
`tests/Fixtures/` instead.

## Coding rules

- Put `declare(strict_types=1);` at the top of every PHP file.
- Follow the existing structure and naming. Style is enforced by Pint.
- Type everything. Document array shapes and generics so PHPStan can verify them.
- Keep the core (`src/` outside `src/Laravel` and `src/Symfony`) free of framework
  dependencies. See [ADR 0003](docs/adr/0003-framework-independent-core.md).
- Do not add retrieval, embedding or generation logic. That belongs in the service.
- Add tests with every behaviour change, including the failure cases.

## Commits and pull requests

- Use [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`,
  `docs:`, `test:`, `ci:`, `chore:` or `refactor:`, in the imperative mood, with a subject
  under 72 characters.
- Keep commits focused. A commit should leave the checks green.
- Add a line under "Unreleased" in [CHANGELOG.md](CHANGELOG.md) for any change that users
  will notice.
- Fill in the pull request template.

## Architecture decisions

Significant design decisions are recorded as ADRs in [`docs/adr/`](docs/adr/), as
described in [ADR 0001](docs/adr/0001-record-architecture-decisions.md).

To propose one, add the next numbered file (`NNNN-short-title.md`) with the sections
Title, Status, Date, Context, Decision and Consequences, and open a pull request. Accepted
ADRs are never edited. To change a decision, write a new ADR that supersedes the old one
and mark the old one "Superseded by NNNN".

## Reporting bugs and vulnerabilities

Open an issue for bugs and feature requests. Report security vulnerabilities privately, as
described in [SECURITY.md](SECURITY.md).
