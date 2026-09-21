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
