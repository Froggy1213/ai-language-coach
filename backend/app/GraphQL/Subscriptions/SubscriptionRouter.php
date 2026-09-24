<?php

namespace App\GraphQL\Subscriptions;

use Illuminate\Contracts\Routing\Registrar;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Nuwave\Lighthouse\Subscriptions\SubscriptionController;

/**
 * Registers the channel-authorization route with the Sanctum stateful
 * middleware.
 *
 * Lighthouse's own router registers `POST /graphql/subscriptions/auth` bare.
 * Without session middleware the browser's `laravel_session` cookie is never
 * read, so `$request->user()` inside a subscription's `authorize()` is null and
 * every channel the SPA asks for comes back forbidden. A test cannot see that
 * on its own — `Sanctum::actingAs()` installs a user resolver that hides the
 * missing session — which is why `AssessmentReadyChannelTest` asserts that this
 * middleware is on the route rather than only that authorization succeeds.
 */
final class SubscriptionRouter
{
    public function reverb(Registrar $router): void
    {
        $router->post('graphql/subscriptions/auth', [
            'as' => 'lighthouse.subscriptions.auth',
            'uses' => SubscriptionController::class.'@authorize',
        ])->middleware([EnsureFrontendRequestsAreStateful::class]);
    }
}
