# Quick start

This guide takes you from nothing to a first answer: start the service, install the
package, upload a document and ask a question. Start with plain PHP, then read the section
for your framework.

## 1. Start the service

`ragbridge/php` is a client. It needs a running ragbridge service, which you can start
with Docker. The [service README](https://github.com/ragbridge-ai/ragbridge#readme) has the
full instructions and the hosted-model alternatives. In short, with
[Ollama](https://ollama.com) and Docker installed:

```bash
git clone https://github.com/ragbridge-ai/ragbridge.git
cd ragbridge
cp .env.example .env

ollama pull nomic-embed-text
ollama pull llama3.2

docker compose up --build --detach
docker compose exec app ragbridge-admin create-tenant --name my-app
```

The last command prints an API key **once**. Copy it. The service listens on
`http://localhost:8000`.

## 2. Install the package

You need PHP 8.2 or later and a PSR-18 HTTP client with PSR-17 factories. Guzzle provides
both and is the usual choice:

```bash
composer require ragbridge/php guzzlehttp/guzzle
```

If your application already has an HTTP client, such as Symfony HttpClient with
`nyholm/psr7`, you do not need Guzzle. The package finds the clients that are installed.

## 3. Plain PHP

Save this as `ask.php` next to your `composer.json`, with the API key from step 1. It
accepts the path of a file and a question:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Ragbridge\Dto\DocumentStatus;
use Ragbridge\Exception\RagbridgeException;
use Ragbridge\RagbridgeClient;

[, $file, $question] = $argv + [null, null, null];

if ($file === null || $question === null) {
    fwrite(STDERR, "Usage: php ask.php <file.pdf|file.md|file.txt> \"<question>\"\n");
    exit(1);
}

$client = RagbridgeClient::create('http://localhost:8000', getenv('RAGBRIDGE_API_KEY') ?: null);

try {
    // Upload the file. Large files are processed in the background, so wait until it is ready.
    $document = $client->upload($file);

    while (in_array($document->status, [DocumentStatus::Pending, DocumentStatus::Processing], true)) {
        sleep(1);
        $document = $client->document($document->id);
    }

    if ($document->status === DocumentStatus::Failed) {
        fwrite(STDERR, "The service could not process the file: {$document->error}\n");
        exit(1);
    }

    // Ask a question about the documents you uploaded.
    $result = $client->query($question);

    echo $result->answer, "\n\nSources:\n";

    foreach ($result->sources as $source) {
        printf("- %s, chunk %d (score %.2f)\n", $source->filename, $source->chunkIndex, $source->score);
    }
} catch (RagbridgeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
```

Run it:

```bash
export RAGBRIDGE_API_KEY=rb_your-key
php ask.php handbook.pdf "How many days of leave do employees get?"
```

The service accepts plain text, Markdown and PDF files. Uploading the same file twice is
safe: the service recognises the content and returns the existing document.

To remove a document, call `$client->deleteDocument($document->id)`. To see what is stored,
call `$client->documents()`.

## 4. Laravel

Install the package. Laravel already includes Guzzle, and the service provider is
discovered automatically:

```bash
composer require ragbridge/php
```

Add the connection to `.env`:

```dotenv
RAGBRIDGE_BASE_URL=http://localhost:8000
RAGBRIDGE_API_KEY=rb_your-key
```

Ask a question from anywhere, for example a route in `routes/web.php`:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Ragbridge\Laravel\Facades\Ragbridge;

Route::get('/ask', function (Request $request) {
    $result = Ragbridge::query($request->string('q')->toString());

    return [
        'answer' => $result->answer,
        'sources' => array_map(fn ($source) => $source->filename, $result->sources),
    ];
});
```

Open `/ask?q=How many days of leave do employees get?`. Upload documents with
`Ragbridge::upload($path)`, for example from an Artisan command or a controller that receives
a file. To type-hint the client instead of using the facade, inject
`Ragbridge\RagbridgeClient`. See the [Laravel guide](laravel.md) for publishing the
configuration, setting a timeout and testing.

## 5. Symfony

Install the package with the HTTP client and a PSR-17 implementation:

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

Create `config/packages/ragbridge.yaml`:

```yaml
ragbridge:
    base_url: '%env(RAGBRIDGE_BASE_URL)%'
    api_key: '%env(default::RAGBRIDGE_API_KEY)%'
```

Add the values to `.env.local`:

```dotenv
RAGBRIDGE_BASE_URL=http://localhost:8000
RAGBRIDGE_API_KEY=rb_your-key
```

Inject the client into a controller:

```php
namespace App\Controller;

use Ragbridge\RagbridgeClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AskController extends AbstractController
{
    public function __construct(private readonly RagbridgeClient $ragbridge) {}

    #[Route('/ask')]
    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->ragbridge->query((string) $request->query->get('q'));

        return $this->json([
            'answer' => $result->answer,
            'sources' => array_map(fn ($source) => $source->filename, $result->sources),
        ]);
    }
}
```

Open `/ask?q=How many days of leave do employees get?`. Requests go through your
application's `http_client` service, so timeouts and the profiler work as usual. See the
[Symfony guide](symfony.md) for details.

## Troubleshooting

| Symptom | Cause and fix |
| ------- | ------------- |
| `AuthenticationException` (HTTP 401) | The API key is missing or wrong. Create one with `ragbridge-admin create-tenant`, and check that the environment variable reaches your application. |
| `No PSR-18 clients found` from `create()` | No PSR-18 client is installed. Run `composer require guzzlehttp/guzzle`, or pass your own client to the `RagbridgeClient` constructor. |
| `TransportException` | The service is not reachable at the base URL. Check that it is running and that the URL, including the port, is right. |
| `RequestFailedException` with status 415 | The file type is not supported. Use plain text, Markdown or PDF. |
| The document stays `pending` | Large files are processed by the service's background worker. Check that the worker is running (`docker compose ps`). |
| Answers take a long time | A local model on a machine without a GPU is slow. Give your HTTP client a longer timeout, see the [Laravel](laravel.md) and [Symfony](symfony.md) guides. |
