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
| `composer test:laravel` | Runs the core and Laravel tests only               |
| `composer test:symfony` | Runs the core and Symfony tests only               |

To fix style issues automatically, run `vendor/bin/pint`.

The Laravel and Symfony integrations are tested on their own in CI, with the other
framework's packages removed, so neither may depend on the other. The pipeline also runs the
whole suite against the oldest dependency versions the constraints allow, so do not rely on
a feature that a newer release of a dependency introduced.

Tests must not call a live service. Use the mock HTTP client and the JSON fixtures in
`tests/Fixtures/` instead.

## Integration tests

Most tests never call a live service. A separate group of integration tests runs the client
against a real ragbridge service. They are skipped unless the environment variables
`RAGBRIDGE_INTEGRATION_URL` and `RAGBRIDGE_INTEGRATION_API_KEY` are set, so `composer test`
does not need Docker or a network.

`tests/Integration/start.sh` starts the service with Docker Compose, creates an API key and
prints the variables:

```bash
eval "$(tests/Integration/start.sh)"
vendor/bin/pest --group=integration

docker compose -f tests/Integration/compose.yaml --profile ollama down --volumes
```

The first run builds the service from source and downloads the models, which takes a while.
If Ollama already runs on your machine, use it instead and skip the downloads:

```bash
eval "$(RAGBRIDGE_INTEGRATION_OLLAMA=host tests/Integration/start.sh)"
```

CI runs the group in its own job, `integration`.

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
