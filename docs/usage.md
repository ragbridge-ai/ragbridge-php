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

## List and delete documents

```php
foreach ($client->documents() as $document) {
    echo "{$document->id}  {$document->filename}  {$document->status->value}\n";
}

// Deleting a document also removes everything the service derived from it.
$client->deleteDocument('3f2b8c1e-5d4a-4b7e-9c61-0a1b2c3d4e5f');
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
| `RequestFailedException`     | Any other error status, for example 413, 415 or 429               |
| `TransportException`         | The service could not be reached or the request timed out         |
| `InvalidResponseException`   | The response is not valid JSON or does not match the API schema   |

The exceptions for HTTP errors extend `ApiException`, which provides `statusCode()` and
`body()` (the decoded response body). Invalid arguments, such as a malformed base URL or a
file that does not exist, raise `InvalidArgumentException`.

## Response objects

Responses are immutable objects with typed properties (`Document`, `QueryResult`,
`Source`, `RetrievalInfo`) rather than arrays, so your IDE and static analysis know what
each field is. See
[ADR 0004](adr/0004-typed-response-objects.md) for the reasoning.
