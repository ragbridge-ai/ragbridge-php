# 6. Retry transient failures only, and never POST by default

- Status: Accepted
- Date: 2026-09-21

## Context

The service runs on the application's own infrastructure. It restarts, its database
becomes unreachable for a moment, and a proxy or load balancer in front of it answers 502,
503 or 504 while it does. It can also answer 429 when it is asked too often. These failures
usually pass within seconds, so repeating the request often turns an error into a slow
success.

Repeating a request is not always safe. When a request times out or the connection drops,
the client does not know whether the service received and processed it. For a request that
only reads, that costs nothing. For a request that changes data, it can be done twice.
HTTP names the methods that can be repeated without changing the result (GET, HEAD, PUT,
DELETE and OPTIONS). POST is not one of them.

Most of what this client sends as POST does not change data: `query()`, `search()` and
`agent()` retrieve and generate, and the service currently answers an upload of content it
already holds with the existing document. But that is how the service behaves today, not a
guarantee of the API, and the client cannot tell from the method alone which POST is safe.

A retry also has a price in a PHP application. The pause blocks the worker that serves the
user's request, and many clients retrying at once during an outage add load to a service
that is trying to recover.

## Decision

Retries are off unless the application turns them on, by passing a `RetryPolicy` to the
client or by enabling `retry` in the Laravel configuration or the Symfony bundle
configuration. A client without a policy behaves as it did before.

**What is retried.** A request is repeated after a transport error (the request could not
be sent, or no response arrived) and after HTTP 429, 502, 503 and 504. Nothing else is:

- 4xx responses other than 429, including validation and authentication errors. The same
  request gets the same answer.
- HTTP 500. It is an error in the service, not a sign that it is busy or restarting, and
  repeating it rarely helps.
- A success response that cannot be read. The request was processed.

**Which methods.** Only idempotent methods are retried by default, which for this client
means GET and DELETE. A POST is retried only when `retry_post` is set. The switch covers
every POST, including uploads, because the client cannot tell which are safe. An
application that knows its service, and wants queries retried, turns it on knowingly.

**How long to wait.** The pause doubles from a base delay up to a maximum, and is
randomised: half of it is fixed and half is random, so clients do not retry in step and no
retry follows the failure immediately. The defaults are 3 attempts in total (two retries),
a base delay of 200 ms and a maximum of 10 seconds. When the service sends `Retry-After`,
as seconds or as a date, that value is used as it is. If it is longer than the maximum
delay, the failure is reported at once instead of being waited for, so the maximum is a
limit the application can rely on.

**Request bodies.** A JSON body is built again for each try. A streamed upload is rewound
to where it started before it is sent again. An upload whose stream cannot be rewound is
not retried, even with `retry_post`, because the body cannot be sent twice.

**Testing.** The function that waits is a parameter of the client. Tests replace it and
do not sleep.

Not part of this decision: a circuit breaker, a limit on retries across requests, and
different settings for different calls. They can be added later without changing what is
described here.

## Consequences

- Applications that do not enable retries see no change, so the feature can ship in a minor
  release.
- The worst-case duration of a call becomes the sum of the pauses plus the time of every
  attempt. The HTTP client's timeout applies to each attempt, not to the whole call, so an
  application that retries should set one.
- The pause blocks the calling process. Work that can wait, such as indexing, belongs in a
  queued job, where the queue's own retry mechanism applies, and not in a long retry loop
  in a web request.
- A DELETE whose response was lost is repeated. If the first request had deleted the
  document, the repeat gets 404 and raises `NotFoundException`, although the document is
  gone. The application can treat that exception as success for a delete.
- Because HTTP 500 is not retried, a service that answers 500 while it starts up is not
  covered. The maximum number of attempts and the delays are configurable, but the list of
  statuses is not.
- `RetryPolicy` is part of the public API and follows semantic versioning, as do the
  `retry` configuration keys. The jitter is not configurable, and its exact distribution is
  not part of the API.
