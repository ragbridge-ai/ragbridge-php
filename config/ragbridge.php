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

];
