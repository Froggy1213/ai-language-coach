<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TelescopeServiceProvider;
use Laravel\Telescope\TelescopeApplicationServiceProvider;
use Nuwave\Lighthouse\Subscriptions\SubscriptionServiceProvider;

$providers = [
    AppServiceProvider::class,
    HorizonServiceProvider::class,

    // Lighthouse ships subscriptions as an opt-in extension: the provider is
    // absent from the package's auto-discovery list, and without it
    // `@subscription` is an unknown directive while the channel-authorization
    // route is never registered. See README -> "GraphQL subscriptions".
    SubscriptionServiceProvider::class,
];

// Telescope is a development-only dependency (composer.json -> require-dev).
// Production images are built with `composer install --no-dev`, and registering
// this provider there makes the whole application fail to boot. Guarding it
// instead of removing it keeps local development working with no extra setup.
if (class_exists(TelescopeApplicationServiceProvider::class)) {
    $providers[] = TelescopeServiceProvider::class;
}

return $providers;
