# ragbridge/php

[![CI](https://github.com/ragbridge-ai/ragbridge-php/actions/workflows/ci.yml/badge.svg)](https://github.com/ragbridge-ai/ragbridge-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

PHP client for the ragbridge retrieval-augmented generation service.

ragbridge is a self-hosted service that exposes retrieval-augmented generation over an
HTTP API: you upload documents, then ask questions and receive answers together with the
sources they were drawn from. This package lets PHP applications use that API with a few
lines of code, from plain PHP, Laravel or Symfony.

All retrieval, embedding and generation logic lives in the service. This package handles
transport, typed responses, error handling and framework integration only.

## Status

**In development.** The package is not released yet and its API is not stable. See the
[roadmap](docs/roadmap.md) for what is planned.

## Requirements

- PHP 8.2 or later
- A running ragbridge service
- A PSR-18 HTTP client and PSR-17 factories, for example Guzzle, Symfony HttpClient or
  `nyholm/psr7` (most applications already have one; see
  [ADR 0002](docs/adr/0002-psr18-http-client.md))

## Installation

```bash
composer require ragbridge/php
```

The package has not been published to Packagist yet. Until it is, install it from the
repository as a VCS source.

## Usage

### Create a client

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

### Upload a document

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

### Ask a question

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

### List and delete documents

```php
foreach ($client->documents() as $document) {
    echo "{$document->id}  {$document->filename}  {$document->status->value}\n";
}

$client->deleteDocument($document->id);
```

### Handle errors

Every failed call throws an exception that implements `Ragbridge\Exception\RagbridgeException`,
so you can catch them all in one place or handle specific cases:

```php
use Ragbridge\Exception\AuthenticationException;
use Ragbridge\Exception\NotFoundException;
use Ragbridge\Exception\RagbridgeException;
use Ragbridge\Exception\ValidationException;

try {
    $result = $client->query($question, topK: 50);
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

### Response objects

Responses are immutable objects with typed properties (`Document`, `QueryResult`,
`Source`, `RetrievalInfo`) rather than arrays. See
[ADR 0004](docs/adr/0004-typed-response-objects.md) for the reasoning.

## Framework integrations

The core client works anywhere. The Laravel and Symfony integrations add configuration and
service registration on top of it. They are independent: each works without the other
framework installed, and the core never depends on either
([ADR 0003](docs/adr/0003-framework-independent-core.md),
[ADR 0005](docs/adr/0005-integrations-in-one-package.md)).

### Laravel

Supported: Laravel 12 and 13. The service provider and the `Ragbridge` alias are
discovered automatically, so installing the package is enough. Set the connection in
`.env`:

```dotenv
RAGBRIDGE_BASE_URL=http://localhost:8000
RAGBRIDGE_API_KEY=your-api-key
```

`RAGBRIDGE_API_KEY` can be left out for a service that does not require one. To change the
configuration file, publish it:

```bash
php artisan vendor:publish --tag=ragbridge-config
```

The client is a singleton in the container. Type-hint it, or use the facade:

```php
use Illuminate\Http\Request;
use Ragbridge\Laravel\Facades\Ragbridge;
use Ragbridge\RagbridgeClient;

// Dependency injection
final class AskController
{
    public function __invoke(Request $request, RagbridgeClient $ragbridge): string
    {
        return $ragbridge->query($request->string('question')->toString())->answer;
    }
}

// Facade
$result = Ragbridge::query('How many days of leave do employees get?');
```

The client sends requests through the PSR-18 client that discovery finds, which is Guzzle in
a standard Laravel application. Guzzle has no timeout by default. To set one, bind the client
yourself in a service provider:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Ragbridge\RagbridgeClient;

$this->app->singleton(RagbridgeClient::class, function () {
    $factory = new HttpFactory();

    return new RagbridgeClient(
        new Client(['timeout' => 30]),
        $factory,
        $factory,
        config('ragbridge.base_url'),
        config('ragbridge.api_key'),
    );
});
```

In tests, replace the facade with a mock:

```php
use Ragbridge\Dto\QueryResult;
use Ragbridge\Laravel\Facades\Ragbridge;

Ragbridge::shouldReceive('query')
    ->once()
    ->with('How many days of leave do employees get?')
    ->andReturn(new QueryResult('25 days.', []));
```

### Symfony

Supported: Symfony 6.4, 7 and 8. Install the package together with the HTTP client and a PSR-17
implementation:

```bash
composer require ragbridge/php symfony/http-client nyholm/psr7
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Ragbridge\Symfony\RagbridgeBundle::class => ['all' => true],
];
```

Configure it in `config/packages/ragbridge.yaml`:

```yaml
ragbridge:
    base_url: '%env(RAGBRIDGE_BASE_URL)%'    # required
    api_key: '%env(default::RAGBRIDGE_API_KEY)%'    # optional
```

```dotenv
RAGBRIDGE_BASE_URL=http://localhost:8000
RAGBRIDGE_API_KEY=your-api-key
```

The client is registered for autowiring, and as the `ragbridge.client` service alias:

```php
use Ragbridge\RagbridgeClient;

final class AskController
{
    public function __construct(private RagbridgeClient $ragbridge) {}

    public function __invoke(Request $request): Response
    {
        $result = $this->ragbridge->query((string) $request->query->get('question'));

        return new Response($result->answer);
    }
}
```

Requests are sent through the application's `http_client` service, so its options, such as
timeouts, and the profiler apply. Set them under `framework.http_client`. Without
`symfony/http-client` the bundle falls back to whatever PSR-18 client discovery finds.

## Documentation

- [Roadmap](docs/roadmap.md)
- [Architecture decision records](docs/adr/)
- [Changelog](CHANGELOG.md)

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) first. To report
a security issue, follow [SECURITY.md](SECURITY.md).

## License

Released under the [MIT License](LICENSE).
