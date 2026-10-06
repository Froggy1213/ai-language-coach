<?php

namespace Tests\Feature\GraphQL;

use Nuwave\Lighthouse\Subscriptions\Contracts\StoresSubscriptions;
use Nuwave\Lighthouse\Subscriptions\Storage\RedisStorageManager;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Pins the wiring that subscriptions need and that fails silently without it:
 * the channel-authorization route comes from an opt-in service provider, and the
 * storage driver must not be a Laravel cache store (see README).
 */
class SubscriptionWiringTest extends TestCase
{
    use MakesGraphQLRequests;

    public function test_channel_authorization_route_forbids_an_unknown_channel(): void
    {
        $this->postJson('/graphql/subscriptions/auth', [
            'channel_name' => 'private-lighthouse-unknown',
            'socket_id' => '123.456',
        ])
            ->assertForbidden()
            ->assertJsonPath('error', 'unauthorized');
    }

    public function test_subscription_storage_resolves_to_the_redis_manager(): void
    {
        $this->assertInstanceOf(RedisStorageManager::class, $this->app->make(StoresSubscriptions::class));
    }

    public function test_guests_cannot_subscribe_to_subscriptions(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            subscription {
                assessmentReady(userId: "1") { id }
            }
        ')->assertGraphQLErrorMessage('Unauthenticated.');

        $this->graphQL(/** @lang GraphQL */ '
            subscription {
                sessionFeedbackReady(sessionId: "1") { id }
            }
        ')->assertGraphQLErrorMessage('Unauthenticated.');
    }
}
