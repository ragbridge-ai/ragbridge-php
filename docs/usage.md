# Usage

How to use the client in plain PHP. For a first end-to-end run, see the
[quick start](quickstart.md). For Laravel and Symfony, see the [Laravel](laravel.md) and
[Symfony](symfony.md) guides.

The examples below assume that `$client` is a `RagbridgeClient`, created as shown in the
first section.

## Create a client

```php
use Ragbridge\RagbridgeClient;

$client = RagbridgeClient::create('http://localhost:8000', getenv('RAGBRIDGE_API_KEY') ?: null);
```

`create()` finds the PSR-18 client and PSR-17 factories that are installed. The API key is
sent as a bearer token; pass `null` if your service does not require one.

To use an HTTP client you have already configured, for example to set timeouts, pass it to
the constructor:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Ragbridge\RagbridgeClient;

$factory = new HttpFactory(); // implements both request and stream factories

$client = new RagbridgeClient(
    new Client(['timeout' => 30]),
    $factory,
    $factory,
    'http://localhost:8000',
    'your-api-key',
);
```

To have failed requests repeated when the service is briefly unavailable, see
[Retry failed requests](#retry-failed-requests).

## Upload a document

```php
use Ragbridge\Dto\DocumentStatus;

$document = $client->upload('/path/to/handbook.pdf');

// Large files are processed asynchronously. Wait until the document is usable.
while (in_array($document->status, [DocumentStatus::Pending, DocumentStatus::Processing], true)) {
    sleep(1);
    $document = $client->document($document->id);
}

if ($document->status === DocumentStatus::Failed) {
    echo "Processing failed: {$document->error}\n";
}
```

The file is streamed from disk and is never loaded into memory. The service accepts plain
text, Markdown and PDF files. The content type is derived from the file extension, or you
can pass it explicitly:

```php
$client->upload($path, filename: 'policy.md', contentType: 'text/markdown');
```

To upload from a stream, for example a file received in a request, use `uploadStream()`:

```php
$client->uploadStream($psr7Stream, 'notes.txt');
```

## Ask a question

```php
$result = $client->query('How many days of leave do employees get?');

echo $result->answer, "\n";

foreach ($result->sources as $source) {
    printf("%s (chunk %d, score %.2f)\n", $source->filename, $source->chunkIndex, $source->score);
    echo $source->snippet, "\n";
}
```

The number of retrieved chunks, the retrieval strategy and the retrieval details can be
set per call:

```php
use Ragbridge\SearchMode;

$result = $client->query(
    'Who approves travel expenses?',
    topK: 10,
    mode: SearchMode::Hybrid,
    explain: true,
);

foreach ($result->sources as $source) {
    // Populated when explain is true.
    echo $source->retrieval?->vectorRank, ' ', $source->retrieval?->keywordRank, "\n";
}
```

## Search without generating an answer

`search()` runs the same retrieval as `query()` and returns the matching chunks, without
asking the model for an answer. Use it when your application reasons over the chunks itself.

```php
$result = $client->search('parental leave', topK: 5);

foreach ($result->results as $hit) {
    printf("%s (chunk %d, score %.4f)\n", $hit->filename, $hit->chunkIndex, $hit->score);
    echo $hit->content, "\n";
}
```

It takes the same `topK`, `mode` and `explain` arguments as `query()`. A hit carries the
full text of the chunk in `content`, where a source of an answer carries a shorter
`snippet`. With `explain: true` each hit has a `retrieval` property and the result reports
`candidateCount`, the number of chunks that were considered.

## Ask a question that needs several searches

`agent()` lets the service search up to a number of times, then answer from everything it
found. The result lists the searches it ran, so that a wrong answer can be traced back to
what was looked for:

```php
$result = $client->agent('How does leave differ between full-time and part-time staff?', maxSteps: 3);

echo $result->answer, "\n";

foreach ($result->steps as $step) {
    printf("searched \"%s\": %d chunks\n", $step->query, $step->results);
}
```

`maxSteps` is optional; without it the service uses its own limit. The sources are the same
`Source` objects that `query()` returns. The call runs synchronously and can take a while
with a local model, so give your HTTP client a generous timeout.

## Check that the service is running

```php
$client->health()->isOk();    // the service process is running
$client->readiness()->isOk(); // ... and it can reach its database
```

`health()` is a liveness check that does not touch the database. `readiness()` also
checks the database. A service that is not ready answers with HTTP 503, which
`readiness()` reports as a `ServerException`, so use it like this:

```php
use Ragbridge\Exception\RagbridgeException;

try {
    $client->readiness();
} catch (RagbridgeException $e) {
    // Not ready, or not reachable: the message says which.
}
```

Neither check needs a valid API key.

## List and delete documents

```php
foreach ($client->documents() as $document) {
    echo "{$document->id}  {$document->filename}  {$document->status->value}\n";
}

// Deleting a document also removes everything the service derived from it.
$client->deleteDocument('3f2b8c1e-5d4a-4b7e-9c61-0a1b2c3d4e5f');
```

## Keep documents in sync with your records

Uploading a file is enough for a folder of PDFs. If your application already has records,
such as articles, products or help pages, identify each document by **your** id for the
record instead. You never have to store the service's ids, an edit replaces the old text
instead of adding a second document, and every call is safe to repeat.

This needs ragbridge service 1.2.0 or later.

```php
use Ragbridge\Dto\SyncResult;

$synced = $client->putDocument(
    externalId: 'article:42',
    title: 'Refund policy',
    content: 'Refunds are possible within 14 days of purchase.',
    metadata: ['locale' => 'en'],
    sourceUpdatedAt: $article->updated_at, // any DateTimeInterface
);

if ($synced->result === SyncResult::Created) {
    echo "New document {$synced->document->id}\n";
}

$document = $client->getByExternalId('article:42');

$client->deleteByExternalId('article:42');
```

`putDocument()` creates the document, or replaces it when the id exists. Send the **whole
current state** of the record every time, for example from a job that runs after each save.
The service compares the text by its hash, so a record that did not change costs one lookup
and no embedding. The title is the document's name in the `filename` of the sources of an
answer.

In a Laravel or a Symfony application, [Sync](sync.md) does the model-to-document mapping
and the job or message dispatch for you: implement one interface on the model or entity,
and it is kept in step with the service automatically.

`deleteByExternalId()` does not fail when there is no such document, so repeating a delete
is safe. That holds for a valid id: an id with characters that are not allowed is a
`ValidationException` (HTTP 422), as it is for the other two calls.

### What happened

The returned `SyncedDocument` has the `document`, the `result` and the HTTP `statusCode`:

| `result`                 | Meaning                                                                    |
| ------------------------ | -------------------------------------------------------------------------- |
| `SyncResult::Created`    | There was no document with this id, and one was made                       |
| `SyncResult::Replaced`   | The text changed: it was chunked and embedded again                        |
| `SyncResult::Updated`    | Only the title or the metadata changed; nothing was embedded again         |
| `SyncResult::Unchanged`  | The same record again; nothing was written                                 |
| `SyncResult::Stale`      | Ignored, because the record is older than the one the service holds        |

`Stale` is not an error: the newer state is already there, and the returned document is as
it was.

### The order of changes

Queues deliver out of order. Send the time the record was **last changed** in your
application as `sourceUpdatedAt`, not the time of the call. When it is older than the stored
one, the service ignores the change. Equal times are applied, because that is almost always a
repeat. A call without a time is always applied and keeps the stored time. The time is sent
with its time zone and with microseconds, so two changes in the same second keep their order.
The service returns the time in UTC, so `$document->sourceUpdatedAt` is the same instant, not
necessarily the same wall-clock time.

### The id

The id is 1 to 255 characters from `A-Z a-z 0-9 . _ : @ -`, starts with a letter or a digit,
is case-sensitive and unique per tenant. A slash is not allowed. The client encodes the id
for you (`article:42` is sent as `article%3A42`). It refuses an empty id, an id with a slash
and `.` or `..` before sending anything and raises an `InvalidArgumentException`. The service
does not answer those with a validation error: an encoded slash (`%2F`) is decoded before the
request is routed, so it gets a **404**, not a 422. An empty id is redirected with a 307 to a
path that means something else: a client that follows redirects sees a 422 about a UUID on GET
and DELETE, and a 405 on PUT. `.` and `..` are removed from a path by HTTP clients. For every other id the service decides,
and answers with a `ValidationException` (HTTP 422) that names the id.

### Metadata

`metadata` is an optional map of your own data, up to 16 KB when encoded. It is returned
with the document as `$document->metadata` and it is not searched. It is part of the state
of the record: metadata that you leave out is removed from the document. An empty array is
left out of the request, and a list such as `['a', 'b']` is refused, because the service
only accepts a JSON object. Keys of the map that look like numbers come back as numbers, and
the service does not keep the order of the keys.

### Large text

Text above the size the service processes within the request (100 KB by default) is saved
as pending and embedded by its worker. The response is then a `202`, and
`$synced->isQueued()` is true. Wait for the document with `waitUntilProcessed()`:

```php
use Ragbridge\Dto\DocumentStatus;

$synced = $client->putDocument('manual:7', 'Boiler manual', $longText);

if ($synced->isQueued()) {
    $document = $client->waitUntilProcessed($synced->document, timeoutSeconds: 120);

    if ($document->status === DocumentStatus::Failed) {
        // $document->error says why. Send the same record again to retry it.
    }
}
```

`waitUntilProcessed()` fetches the document again every second, or at the `intervalMs` you
give, until it is ready or failed. A failed document is returned and not thrown. After the
timeout it raises a `ProcessingTimeoutException`, which carries the document as it was last
seen; the service keeps working on it. It also works for large uploads.

When the text of an existing document is replaced, the old version stays searchable until
the new one is ready, and stays if the new one fails.

A model that runs on the same machine can fail when it is given several large texts at the
same time. The document is then `failed`, and its `error` holds the error of the model.
Service 1.3.0 limits how many documents a worker embeds at once (the setting
`WORKER_MAX_JOBS`, 2 by default), which makes this rare. Send the same record again to retry
a document that failed.

### When to send the request again

| Exception                       | Meaning                                                         | What to do                         |
| ------------------------------- | --------------------------------------------------------------- | ---------------------------------- |
| `ValidationException` (422)     | A value is not allowed; `errors()` names the field              | Fix the value. Sending it again does not help |
| `RequestFailedException` (413)  | The text is over the service's size limit, 10 MB by default     | Send less text                     |
| `ConflictException` (409)       | The request lost a race with a delete                           | Send it again                      |
| `ServiceUnavailableException` (503) | The service cannot reach its job queue; it marked the document as failed | Send the same record again later |

A `PUT` is idempotent, so a [retry policy](#retry-failed-requests) repeats it after a
transport error and after 429, 502, 503 and 504. It does not repeat a 409, so that your
application decides.

## Retry failed requests

Retries are off by default. To repeat requests that failed for a transient reason, give the
client a `RetryPolicy`:

```php
use Ragbridge\RagbridgeClient;
use Ragbridge\RetryPolicy;

$client = RagbridgeClient::create(
    'http://localhost:8000',
    getenv('RAGBRIDGE_API_KEY') ?: null,
    new RetryPolicy(maxAttempts: 4, baseDelayMs: 200, maxDelayMs: 10_000),
);
```

The constructor takes the policy as its sixth argument, after the API key.

A request is repeated after a transport error (no response arrived) and after HTTP 429,
502, 503 or 504. Validation, authentication and other 4xx errors, and HTTP 500, are never
retried, because the same request would get the same answer.

| Option          | Default  | Meaning                                                                 |
| --------------- | -------- | ----------------------------------------------------------------------- |
| `maxAttempts`   | `3`      | Total number of tries including the first, so 3 means up to two retries |
| `baseDelayMs`   | `200`    | Pause before the first retry; it doubles for each further retry         |
| `maxDelayMs`    | `10000`  | Longest pause between two tries                                         |
| `retryPost`     | `false`  | Also retry POST requests                                                |

The pause is randomised, so that many clients do not retry at the same moment. When the
service sends a `Retry-After` header, its value is used instead. If it asks for longer than
`maxDelayMs`, the failure is reported at once.

By default only requests with an idempotent method, which are GET, PUT and DELETE, are repeated. A POST, which covers `query()`,
`search()`, `agent()` and uploads, is repeated only with `retryPost: true`. If a response
is lost, the service may already have processed the request, and the client cannot know
whether repeating it is harmless. A query only costs compute, and uploading the same
content again returns the existing document, so many applications turn it on. See
[ADR 0006](adr/0006-retry-policy.md) for the reasoning.

Keep two things in mind. The timeout of your HTTP client applies to each try, not to the
whole call, so set one. And the pauses block the process that makes the call: for work that
can wait, such as indexing, use a queued job instead of long retries in a web request.

To test code that retries without waiting, pass a function as the last constructor
argument. It receives the pause in milliseconds and replaces `usleep()`:

```php
$client = new RagbridgeClient(
    $httpClient,
    $factory,
    $factory,
    'http://localhost:8000',
    'your-api-key',
    new RetryPolicy(),
    static function (int $milliseconds): void {}, // do not wait
);
```

## Handle errors

Every failed call throws an exception that implements `Ragbridge\Exception\RagbridgeException`,
so you can catch them all in one place or handle specific cases:

```php
use Ragbridge\Exception\AuthenticationException;
use Ragbridge\Exception\NotFoundException;
use Ragbridge\Exception\RagbridgeException;
use Ragbridge\Exception\ValidationException;

try {
    $result = $client->query('How many days of leave do employees get?', topK: 50);
} catch (ValidationException $e) {
    foreach ($e->errors() as $error) {
        echo implode('.', $error['loc']), ': ', $error['msg'], "\n";
    }
} catch (AuthenticationException) {
    echo "Check the API key.\n";
} catch (RagbridgeException $e) {
    echo $e->getMessage(), "\n";
}
```

| Exception                    | Cause                                                             |
| ---------------------------- | ----------------------------------------------------------------- |
| `AuthenticationException`    | HTTP 401 or 403                                                   |
| `NotFoundException`          | HTTP 404                                                          |
| `ValidationException`        | HTTP 422; `errors()` lists the invalid fields                     |
| `ServerException`            | HTTP 5xx                                                          |
| `ServiceUnavailableException`| HTTP 503; a kind of `ServerException`                             |
| `ConflictException`          | HTTP 409; a kind of `RequestFailedException`                      |
| `RequestFailedException`     | Any other error status, for example 413, 415 or 429               |
| `TransportException`         | The service could not be reached or the request timed out         |
| `InvalidResponseException`   | The response is not valid JSON or does not match the API schema   |
| `ProcessingTimeoutException` | `waitUntilProcessed()` ran out of time; the document is still processed |

The exceptions for HTTP errors extend `ApiException`, which provides `statusCode()` and
`body()` (the decoded response body). Invalid arguments, such as a malformed base URL or a
file that does not exist, raise `InvalidArgumentException`.

## Response objects

Responses are immutable objects with typed properties (`Document`, `QueryResult`,
`Source`, `RetrievalInfo`, `SearchResult`, `SearchHit`, `AgentResult`, `AgentStep`, `HealthStatus`,
`SyncedDocument` and `SyncResult`) rather than arrays, so your IDE and static analysis know what
each field is. See
[ADR 0004](adr/0004-typed-response-objects.md) for the reasoning.
