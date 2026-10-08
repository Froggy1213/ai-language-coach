<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // 'graphql/*' is not optional: the subscription channel-authorization route
    // lives at /graphql/subscriptions/auth, and a bare 'graphql' matches that
    // path exactly — so the one request Echo's custom authorizer makes came back
    // without CORS headers, the private channel was never subscribed, and the
    // result push had nowhere to land.
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'graphql', 'graphql/*'],

    'allowed_methods' => ['*'],

    // Explicit origins only: a wildcard combined with supports_credentials would
    // let any site make credentialed requests. Comma-separated, whitespace and
    // empty entries dropped. Production sets this to the app's own origin.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://127.0.0.1:3000')),
    ), fn (string $origin): bool => $origin !== '')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Cookie-based Sanctum SPA auth from the Nuxt dev server needs credentials,
    // which is why allowed_origins above must never contain '*'.
    'supports_credentials' => true,

];
