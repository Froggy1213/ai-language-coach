<?php

namespace Tests\Feature\GraphQL;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * The subscription path an SPA actually walks: ask for the channel over HTTP
 * with the socket id, then authorize that private channel.
 *
 * The route Lighthouse registers on its own has no middleware, so the session
 * never starts and every authorize() sees a guest — this test fails without the
 * router in App\GraphQL\Subscriptions (README, decision 17).
 */
class AssessmentReadyChannelTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const SPA_HEADERS = [
        'Origin' => 'http://localhost:3000',
        'X-Socket-ID' => '1234.5678',
    ];

    private const SUBSCRIBE = /** @lang GraphQL */ '
        subscription ($userId: ID!) {
            assessmentReady(userId: $userId) {
                id
                status
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();

        // Subscriptions outlive the test database — they live in Redis — and a
        // subscriber whose user has been wiped cannot be restored (its context
        // holds the model), so reading it back throws. Start each test from an
        // empty subscription storage instead; deleting the raw keys avoids the
        // restore that `subscribersByTopic()` would do.
        $redis = Redis::connection(config('lighthouse.subscriptions.broadcasters.echo.connection', 'default'));

        foreach ($redis->keys('*graphql.*') as $key) {
            $redis->del($key);
        }
    }

    public function test_the_subscription_response_names_the_channel_to_listen_on(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->graphQL(self::SUBSCRIBE, ['userId' => '1'], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree();

        $this->assertNotNull($response->json('extensions.lighthouse_subscriptions.channel'));
    }

    public function test_the_owner_may_authorize_the_channel_of_their_own_subscription(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $channel = $this->channelFor($user);

        $this->postJson('/graphql/subscriptions/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ], self::SPA_HEADERS)->assertOk();
    }

    public function test_another_learner_may_not_authorize_that_channel(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $channel = $this->channelFor($user);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/graphql/subscriptions/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ], self::SPA_HEADERS)->assertForbidden();
    }

    public function test_a_guest_may_not_authorize_the_channel(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $channel = $this->channelFor($user);

        $this->app['auth']->forgetGuards();

        $this->postJson('/graphql/subscriptions/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ], self::SPA_HEADERS)->assertForbidden();
    }

    public function test_the_authorization_route_starts_the_spa_session(): void
    {
        $route = collect(Route::getRoutes())
            ->first(static fn ($route): bool => $route->getName() === 'lighthouse.subscriptions.auth');

        $this->assertNotNull($route, 'The channel-authorization route is not registered.');

        // The session middleware is the point: a browser reaches this route with
        // a cookie and nothing else. Sanctum::actingAs() installs a user
        // resolver that hides a missing session, so it is asserted directly.
        $this->assertContains(EnsureFrontendRequestsAreStateful::class, $route->middleware());
    }

    /**
     * The SPA is a different origin from the API, so a response without CORS
     * headers is a response the browser throws away: pusher-js reports
     * "Failed to fetch", the private channel never subscribes, and the waiting
     * onboarding screen never hears about its own assessment.
     */
    public function test_the_authorization_response_is_reachable_from_the_spa_origin(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/graphql/subscriptions/auth', [
            'channel_name' => $this->channelFor($user),
            'socket_id' => '1234.5678',
        ], self::SPA_HEADERS)
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    }

    private function channelFor(User $user): string
    {
        $response = $this->graphQL(self::SUBSCRIBE, ['userId' => (string) $user->id], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree();

        return (string) $response->json('extensions.lighthouse_subscriptions.channel');
    }
}
