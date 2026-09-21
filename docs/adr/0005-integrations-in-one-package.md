# 5. Keep the Laravel and Symfony integrations in this package

- Status: Accepted
- Date: 2026-09-21

## Context

Framework support could live in separate packages, `ragbridge/laravel` and
`ragbridge/symfony`, or in this one under `src/Laravel` and `src/Symfony`.

Separate packages give each integration its own version, its own framework constraints and
its own release rhythm. They also mean three repositories or three release processes, and
every change that spans the core and an integration has to be coordinated across versions.

The integrations are currently small: a service provider, a facade and a configuration file
for Laravel, and a bundle with a configuration tree and an extension for Symfony. They
contain wiring and no behaviour, as ADR 0003 requires.

## Decision

Both integrations live in this package for now, in the `Ragbridge\Laravel` and
`Ragbridge\Symfony` namespaces. There is one version and one changelog.

The framework packages are development dependencies, used only to test the integrations,
and are listed under `suggest`. Installing `ragbridge/php` never installs a framework. An
integration works only where its framework is installed, and the two do not depend on each
other.

Splitting an integration into its own package should be reconsidered when one of these
happens:

- **Diverging support.** One framework needs a support policy the other cannot share, for
  example dropping a framework major version, or a breaking release, that the other
  integration's users would be forced to take.
- **Independent release pressure.** Fixes to one integration repeatedly force releases that
  the users of the core and of the other integration do not need.
- **Growth.** An integration grows well beyond wiring, for instance with queue handling,
  commands and event listeners, so that its size or test time dominates the package.
- **Ownership.** Different maintainers take responsibility for an integration and need to
  release it independently.

A split keeps the namespaces, so applications only need to add the new package. The core
package would then no longer contain the integration code, which is a breaking change to
be released as a major version.

## Consequences

- One installation and one version cover the core and both integrations, and a change that
  touches the core and an integration lands in a single pull request.
- The framework constraints are not enforced by Composer, because the frameworks are only
  suggested. An application that uses an integration without its framework gets a
  class-not-found error. The README states the supported framework versions.
- Users of one framework receive the changelog and releases of the other. Release notes
  name the integration they concern.
- The test suites for the integrations need the framework packages, so the test setup
  installs both. Each integration is still tested on its own, so that neither depends on
  the other being present.
- A later split is possible without renaming anything users write, but it is a breaking
  release of this package.
