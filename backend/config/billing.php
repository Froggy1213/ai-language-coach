<?php

/*
|--------------------------------------------------------------------------
| Billing
|--------------------------------------------------------------------------
|
| Prices are stored in the smallest unit Stripe expects. JPY is a
| zero-decimal currency, so 799 means ¥799 and not 799 sen.
|
| Display copy for a plan lives in `frontend/app/constants/strings.ts`, keyed
| by `code`: the backend owns money and minutes, the interface owns Japanese.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe Credentials
    |--------------------------------------------------------------------------
    |
    | `secret` is the server API key (`sk_...`), `webhook_secret` is the signing
    | secret of the endpoint (`whsec_...`). Both are empty in local development
    | on purpose: a checkout attempt then fails with BILLING_NOT_CONFIGURED
    | instead of reaching Stripe with a development key.
    |
    */

    'secret' => env('STRIPE_SECRET'),

    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

    'currency' => 'jpy',

    /*
    |--------------------------------------------------------------------------
    | Redirects
    |--------------------------------------------------------------------------
    |
    | The SPA and the API can live on different origins in development, so the
    | success/cancel/return URLs are taken from the browser-facing origin rather
    | than from APP_URL.
    |
    */

    'frontend_url' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost:3000')), '/'),

    /*
    |--------------------------------------------------------------------------
    | Free Allowance
    |--------------------------------------------------------------------------
    |
    | A new learner gets 15 minutes of voice practice without a card. The
    | allowance is lifetime, not monthly: it is the trial, and it is consumed by
    | the same `voice_sessions.duration_sec` the paid allowance uses.
    |
    */

    'free_seconds' => (int) env('BILLING_FREE_SECONDS', 900),

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | One paid tier is a code with a monthly allowance in seconds and two
    | prices. `stripe_price` values are created in the Stripe dashboard and
    | passed through the environment; an empty id is a plan that cannot be
    | bought yet, and the mutation answers BILLING_NOT_CONFIGURED.
    |
    */

    'plans' => [

        'entry' => [
            'monthly_seconds' => 150 * 60,
            'prices' => [
                'month' => [
                    'amount' => 799,
                    'stripe_price' => env('STRIPE_PRICE_ENTRY_MONTHLY'),
                ],
                'year' => [
                    'amount' => 7990,
                    'stripe_price' => env('STRIPE_PRICE_ENTRY_YEARLY'),
                ],
            ],
        ],

        'standard' => [
            'monthly_seconds' => 300 * 60,
            'prices' => [
                'month' => [
                    'amount' => 1480,
                    'stripe_price' => env('STRIPE_PRICE_STANDARD_MONTHLY'),
                ],
                'year' => [
                    'amount' => 14800,
                    'stripe_price' => env('STRIPE_PRICE_STANDARD_YEARLY'),
                ],
            ],
        ],

        'pro' => [
            'monthly_seconds' => 700 * 60,
            'prices' => [
                'month' => [
                    'amount' => 2980,
                    'stripe_price' => env('STRIPE_PRICE_PRO_MONTHLY'),
                ],
                'year' => [
                    'amount' => 29800,
                    'stripe_price' => env('STRIPE_PRICE_PRO_YEARLY'),
                ],
            ],
        ],

    ],

];
