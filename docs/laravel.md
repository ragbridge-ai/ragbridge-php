# Laravel

The Laravel integration registers the client in the container and adds a facade. The client
itself is described in the [usage guide](usage.md).

Supported: Laravel 12 and 13.

## Installation

```bash
composer require ragbridge/php
```

Laravel already includes an HTTP client (Guzzle), so nothing else is needed. The service
provider and the `Ragbridge` facade alias are discovered automatically.

## Configuration

Set the connection in `.env`:

```dotenv
RAGBRIDGE_BASE_URL=http://localhost:8000
RAGBRIDGE_API_KEY=your-api-key
```

`RAGBRIDGE_API_KEY` can be left out for a service that does not require one. To change the
configuration file, publish it to `config/ragbridge.php`:

```bash
php artisan vendor:publish --tag=ragbridge-config
```

## Retries

Retries are off by default. To repeat requests that fail because the service is briefly
unavailable, enable them in `.env`:

```dotenv
RAGBRIDGE_RETRY_ENABLED=true
```

| Variable                        | Default | Meaning                                                              |
| ------------------------------- | ------- | -------------------------------------------------------------------- |
| `RAGBRIDGE_RETRY_ENABLED`       | `false` | Turns retries on                                                     |
| `RAGBRIDGE_RETRY_MAX_ATTEMPTS`  | `3`     | Total number of tries, including the first                           |
| `RAGBRIDGE_RETRY_BASE_DELAY_MS` | `200`   | Pause before the first retry; it doubles for each further retry      |
| `RAGBRIDGE_RETRY_MAX_DELAY_MS`  | `10000` | Longest pause between two tries                                      |
| `RAGBRIDGE_RETRY_POST`          | `false` | Also retry POST requests: queries, searches, the agent and uploads   |

They are the `retry` section of `config/ragbridge.php`. A request is repeated after a
connection error and after HTTP 429, 502, 503 or 504, and never after other 4xx errors or
HTTP 500. Only GET, PUT and DELETE requests are repeated unless `RAGBRIDGE_RETRY_POST` is on.
The [usage guide](usage.md#retry-failed-requests) explains the rules, and
[ADR 0006](adr/0006-retry-policy.md) the reasoning.

If you bind the client yourself, as in [Setting a timeout](#setting-a-timeout), pass a
`RetryPolicy` as the sixth constructor argument to keep retries.

## Usage

The client is a singleton in the container. Type-hint it in a controller, a job or any
other class the container builds:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Ragbridge\RagbridgeClient;

class AskController extends Controller
{
    public function __invoke(Request $request, RagbridgeClient $ragbridge): array
    {
        $result = $ragbridge->query($request->string('question')->toString());

        return [
            'answer' => $result->answer,
            'sources' => array_map(fn ($source) => $source->filename, $result->sources),
        ];
    }
}
```

Or use the facade, which offers the same methods:

```php
use Ragbridge\Laravel\Facades\Ragbridge;

$result = Ragbridge::query('How many days of leave do employees get?');
```

Besides `query()` and the document methods, the client can search without generating an
answer (`search()`), run a multi-step question (`agent()`), check the service
(`health()` and `readiness()`) and keep documents in step with your records by your own
ids (`putDocument()`, `getByExternalId()` and `deleteByExternalId()`, which need service 1.2.0
or later). They are described in
the [usage guide](usage.md). For example, from an observer or a queued job:

```php
Ragbridge::putDocument("article:{$article->id}", $article->title, $article->body, sourceUpdatedAt: $article->updated_at);
```

## Setting a timeout

The client uses the PSR-18 client that is found by discovery, which is Guzzle in a standard
Laravel application. Guzzle has no timeout by default. To set one, bind the client yourself
in `App\Providers\AppServiceProvider`:

```php
<?php

namespace App\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\ServiceProvider;
use Ragbridge\RagbridgeClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RagbridgeClient::class, function (): RagbridgeClient {
            $factory = new HttpFactory(); // implements both request and stream factories

            return new RagbridgeClient(
                new Client(['timeout' => 30]),
                $factory,
                $factory,
                config('ragbridge.base_url'),
                config('ragbridge.api_key'),
                // Pass a RetryPolicy here to retry failed requests, see "Retries" above.
            );
        });
    }
}
```

## Testing

In your own tests, replace the facade with a mock:

```php
use Ragbridge\Dto\QueryResult;
use Ragbridge\Laravel\Facades\Ragbridge;

Ragbridge::shouldReceive('query')
    ->once()
    ->with('How many days of leave do employees get?')
    ->andReturn(new QueryResult('25 days.', []));
```
