# Symfony

The Symfony bundle registers the client as an autowirable service. The client itself is
described in the [usage guide](usage.md).

Supported: Symfony 6.4, 7 and 8.

## Installation

Install the package together with Symfony's HTTP client and a PSR-17 implementation, which
the HTTP client needs to build requests:

```bash
composer require ragbridge/php symfony/http-client nyholm/psr7
```

## Enable the bundle

Add the bundle to `config/bundles.php`:

```php
<?php

return [
    // ...
    Ragbridge\Symfony\RagbridgeBundle::class => ['all' => true],
];
```

## Configuration

Create `config/packages/ragbridge.yaml`:

```yaml
ragbridge:
    base_url: '%env(RAGBRIDGE_BASE_URL)%'
    api_key: '%env(default::RAGBRIDGE_API_KEY)%'
```

| Option     | Required | Description                                                          |
| ---------- | -------- | -------------------------------------------------------------------- |
| `base_url` | yes      | Root URL of the ragbridge service, for example `http://localhost:8000` |
| `api_key`  | no       | Sent to the service as a bearer token                                |
| `retry`    | no       | Retries of requests that failed for a transient reason, off by default; see [Retries](#retries) |

Set the values in `.env.local`:

```dotenv
RAGBRIDGE_BASE_URL=http://localhost:8000
RAGBRIDGE_API_KEY=your-api-key
```

The `default::` prefix makes the API key optional: without the variable, no key is sent.

## Retries

Retries are off by default. To repeat requests that fail because the service is briefly
unavailable, enable them:

```yaml
ragbridge:
    base_url: '%env(RAGBRIDGE_BASE_URL)%'
    retry: true
```

`retry: true` uses the defaults below. To change them, write the options out:

```yaml
ragbridge:
    retry:
        enabled: true
        max_attempts: 4
        base_delay_ms: 200
        max_delay_ms: 10000
        retry_post: false
```

| Option          | Default | Meaning                                                              |
| --------------- | ------- | -------------------------------------------------------------------- |
| `enabled`       | `false` | Turns retries on                                                     |
| `max_attempts`  | `3`     | Total number of tries, including the first                           |
| `base_delay_ms` | `200`   | Pause before the first retry; it doubles for each further retry      |
| `max_delay_ms`  | `10000` | Longest pause between two tries                                      |
| `retry_post`    | `false` | Also retry POST requests: queries, searches, the agent and uploads   |

Every option can be an environment variable, for example
`enabled: '%env(bool:RAGBRIDGE_RETRY)%'` and `max_attempts: '%env(int:RAGBRIDGE_RETRY_ATTEMPTS)%'`.

A request is repeated after a connection error and after HTTP 429, 502, 503 or 504, and
never after other 4xx errors or HTTP 500. Only GET and DELETE requests are repeated unless
`retry_post` is on. The [usage guide](usage.md#retry-failed-requests) explains the rules,
and [ADR 0006](adr/0006-retry-policy.md) the reasoning.

These retries are the bundle's own and are separate from Symfony's `retry_failed` option
for `http_client`. Use one of them, not both, or a request is retried twice over.

## Usage

The client is registered for autowiring. Type-hint it in a controller or any other service:

```php
<?php

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
        $result = $this->ragbridge->query((string) $request->query->get('question'));

        return $this->json([
            'answer' => $result->answer,
            'sources' => array_map(fn ($source) => $source->filename, $result->sources),
        ]);
    }
}
```

Besides `query()` and the document methods, the client can search without generating an
answer (`search()`), run a multi-step question (`agent()`) and check the service
(`health()` and `readiness()`). They are described in the [usage guide](usage.md).

The service id is `Ragbridge\RagbridgeClient`, with `ragbridge.client` as an alias. The
configuration is also available as the container parameters `ragbridge.base_url` and
`ragbridge.api_key`.

## HTTP client and timeouts

Requests are sent through your application's `http_client` service, so its options and the
profiler apply. Set a timeout for all requests under `framework.http_client`:

```yaml
framework:
    http_client:
        default_options:
            timeout: 30
```

Without `symfony/http-client`, the bundle falls back to whichever PSR-18 client is
installed.

## Testing

In the test environment, register Symfony's `MockHttpClient` as the `http_client` service.
The ragbridge client then sends its requests to it.
