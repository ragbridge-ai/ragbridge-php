# 4. Return typed readonly objects instead of arrays

- Status: Accepted
- Date: 2026-09-21

## Context

The service answers with JSON. A PHP client can hand the decoded arrays to the caller or
convert them into objects.

Arrays are the least work to maintain: a new field in the API is available immediately. But
they give callers nothing to rely on. Keys are strings that IDEs cannot complete, a typo
returns `null` silently, and static analysis cannot know what a value is. A response that
does not match the documented shape is only noticed when a caller trips over it.

## Decision

Every response is converted into an immutable object with typed properties: `Document`,
`QueryResult`, `Source` and `RetrievalInfo`, plus a `DocumentStatus` enum for the fixed set
of document states. The classes are `final readonly` and are created through `fromArray()`
factories.

The factories check every field. A missing required field or a field of the wrong type
raises an `InvalidArgumentException` that names the type and the field, and the client
reports it as an `InvalidResponseException`. Optional and nullable fields have explicit
defaults. Fields the package does not know about are ignored.

## Consequences

- Callers get completion, type checking and static analysis for every response, and a
  malformed response fails at the boundary with a precise message.
- Objects cannot be changed after creation, so they are safe to share and cache.
- A field that the service adds is not visible to callers until the package models it, so
  new API fields need a package release. Ignoring unknown fields keeps older releases
  working against a newer service in the meantime.
- A value that the service adds to a closed set, such as a new document status, is rejected
  by the strict checks until the package is updated. This is deliberate: it surfaces the
  change immediately instead of passing an unhandled value on to the application.
- The package carries the cost of keeping the classes in step with the API schema, in
  exchange for a contract that callers can depend on.
