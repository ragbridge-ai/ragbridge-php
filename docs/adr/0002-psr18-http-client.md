# 2. Depend on PSR-18 and PSR-17 instead of a specific HTTP client

- Status: Accepted
- Date: 2026-09-21

## Context

The client needs to send HTTP requests. Guzzle is the most common choice, but a PHP
application usually already ships an HTTP client of its own, such as Guzzle, Symfony
HttpClient or a framework wrapper. A hard dependency on one client forces every consumer
to install it and can cause version conflicts with the version the application already
uses.

PSR-18 defines the client interface, and PSR-17 defines the request, stream and URI
factories. `php-http/discovery` can locate installed implementations at runtime.

## Decision

The package depends on `psr/http-client`, `psr/http-factory` and `php-http/discovery`.
It does not depend on Guzzle or any other concrete implementation.

The client accepts a PSR-18 client and PSR-17 factories through its constructor. A
convenience factory uses discovery to find installed implementations, so usage in an
application that already has a PSR-18 client needs no extra setup.

## Consequences

- No dependency conflicts caused by this package's choice of HTTP client.
- Tests can substitute a mock PSR-18 client and never need the network.
- Users who have no PSR-18 client installed must add one. Discovery reports a clear error
  in that case, and the installation instructions mention it.
- Features that are specific to one client, such as its own retry or middleware system,
  are not available through the package.
