<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Service URL
    |--------------------------------------------------------------------------
    |
    | Root URL of the ragbridge service, without a trailing path.
    |
    */

    'base_url' => env('RAGBRIDGE_BASE_URL', 'http://localhost:8000'),

    /*
    |--------------------------------------------------------------------------
    | API key
    |--------------------------------------------------------------------------
    |
    | Sent to the service as a bearer token. Leave it unset for a service that
    | does not require authentication.
    |
    */

    'api_key' => env('RAGBRIDGE_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Off by default. When enabled, a request that failed for a transient reason
    | (a connection error, or HTTP 429, 502, 503 or 504) is sent again after a
    | growing, randomised pause. A Retry-After header from the service is
    | respected. Validation, authentication and other 4xx errors, and HTTP 500,
    | are never retried.
    |
    | max_attempts   Total number of tries, including the first.
    | base_delay_ms  Pause before the first retry; it doubles for each further one.
    | max_delay_ms   Longest pause between two tries.
    | retry_post     Also retry POST requests (query, search, agent and uploads).
    |                Off by default: a POST whose response was lost may already
    |                have been processed by the service.
    |
    */

    'retry' => [
        'enabled' => env('RAGBRIDGE_RETRY_ENABLED', false),
        'max_attempts' => env('RAGBRIDGE_RETRY_MAX_ATTEMPTS', 3),
        'base_delay_ms' => env('RAGBRIDGE_RETRY_BASE_DELAY_MS', 200),
        'max_delay_ms' => env('RAGBRIDGE_RETRY_MAX_DELAY_MS', 10000),
        'retry_post' => env('RAGBRIDGE_RETRY_POST', false),
    ],

];
