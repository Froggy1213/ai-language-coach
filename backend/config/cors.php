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

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Cookie-based Sanctum SPA auth from the Nuxt dev server needs credentials;
    // the origin list should be locked down before the AWS deploy (§7 checklist).
    'supports_credentials' => true,

];
