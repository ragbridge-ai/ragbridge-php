# 3. Keep the core independent of any framework

- Status: Accepted
- Date: 2026-09-21

## Context

The package is used from Laravel and Symfony applications, and also from plain PHP
projects and other environments such as WordPress. Building the client around one
framework's container, configuration or events would exclude the others and tie the
package's release cycle to that framework's.

## Decision

The core client, response objects and exceptions have no framework dependency. They
depend only on PHP and the PSR interfaces from ADR 0002.

Framework support is a thin layer on top of the core. It handles configuration, service
registration and framework-specific conveniences, and delegates all behaviour to the
core. The core never imports framework code.

## Consequences

- Plain PHP and WordPress projects can use the core directly.
- Framework integrations stay small and easy to review, because they contain wiring and
  no behaviour.
- Framework packages are development dependencies for testing only. Each integration
  must work without the other framework installed.
- Some convenience, such as automatic queue integration, exists only in the framework
  layers, and the core stays deliberately minimal.
