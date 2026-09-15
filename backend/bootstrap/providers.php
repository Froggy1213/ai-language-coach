<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TelescopeServiceProvider;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

$providers = [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
];

// Telescope is a development-only dependency (composer.json -> require-dev).
// Production images are built with `composer install --no-dev`, and registering
// this provider there makes the whole application fail to boot. Guarding it
// instead of removing it keeps local development working with no extra setup.
if (class_exists(TelescopeApplicationServiceProvider::class)) {
    $providers[] = TelescopeServiceProvider::class;
}

return $providers;
