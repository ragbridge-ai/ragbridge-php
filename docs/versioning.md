# Public API and versioning

The package follows [semantic versioning](https://semver.org/spec/v2.0.0.html). The following
is the public API. Breaking changes to it happen only in a major release.

- **Client:** `Ragbridge\RagbridgeClient` (its constructor, `create()`, `query()`,
  `search()`, `agent()`, `health()`, `readiness()`, `upload()`, `uploadStream()`,
  `documents()`, `document()` and `deleteDocument()`), the `Ragbridge\SearchMode` enum and
  `Ragbridge\RetryPolicy`.
- **Response objects:** the classes in `Ragbridge\Dto` (`Document`, `DocumentStatus`,
  `QueryResult`, `Source`, `RetrievalInfo`, `SearchResult`, `SearchHit`, `AgentResult`,
  `AgentStep` and `HealthStatus`), their public properties and their `fromArray()`
  factories.
- **Exceptions:** the classes and the interface in `Ragbridge\Exception`, including
  `statusCode()`, `body()` and `errors()`.
- **Laravel:** `Ragbridge\Laravel\RagbridgeServiceProvider`, the
  `Ragbridge\Laravel\Facades\Ragbridge` facade, the `ragbridge` configuration keys and their
  environment variables (including the `retry` section), and the `Ragbridge\RagbridgeClient`
  and `ragbridge` container bindings.
- **Symfony:** `Ragbridge\Symfony\RagbridgeBundle`, the `ragbridge` configuration keys
  (including the `retry` section), the
  `Ragbridge\RagbridgeClient` service and its `ragbridge.client` alias, and the
  `ragbridge.base_url` and `ragbridge.api_key` parameters.

New features are added in minor releases. This can include new methods, new optional
constructor parameters, new properties on response objects, new cases in `DocumentStatus`
and `SearchMode`, and new exception classes that extend an existing one. Code that matches
on those enums should therefore have a default branch, and code that catches an exception
class keeps working.

**Not covered:**

- Classes and members marked `@internal`. These are `Ragbridge\Internal\Payload`,
  `Ragbridge\Internal\ConcatStream`, `Ragbridge\Internal\MultipartFile`,
  `Ragbridge\Internal\RetryAfter`, `Ragbridge\Symfony\RetryPolicyFactory` and the constructor
  parameter of `Ragbridge\Symfony\DependencyInjection\RagbridgeExtension`.
- Private and protected members, and the wording of exception messages.
- The exact distribution of the random part of a retry delay. The documented bounds, the
  retried statuses and the defaults of `RetryPolicy` are covered.
- The test helpers in `tests/`, and the examples, which are not part of the package.

The minimum PHP version and the supported framework versions change only in a major
release, except that support for a framework version that has reached end of life may be
dropped in a minor release. Response objects follow the service's API: when the service
adds a field, the package models it in a new release
([ADR 0004](adr/0004-typed-response-objects.md)).
