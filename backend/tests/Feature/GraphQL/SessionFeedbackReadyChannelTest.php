<?php

namespace Tests\Feature\GraphQL;

use App\Enums\VoiceSessionStatus;
use App\GraphQL\Subscriptions\SessionFeedbackReady;
use App\Models\Mistake;
use App\Models\User;
use App\Models\VoiceSession;
use Database\Seeders\GrammarPointSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Nuwave\Lighthouse\Subscriptions\BroadcastDriverManager;
use Nuwave\Lighthouse\Subscriptions\Contracts\BroadcastsSubscriptions;
use Nuwave\Lighthouse\Subscriptions\Subscriber;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\Support\ClearsSubscriptionStorage;
use Tests\TestCase;

/**
 * Tests subscription authorization and broadcasting for `sessionFeedbackReady` (plan §4, §5).
 * Mirrors AssessmentReadyChannelTest to verify SPA channel authorization, ownership checks,
 * CORS headers, and Pusher envelope payload shape (README, decisions 18 and 21).
 */
class SessionFeedbackReadyChannelTest extends TestCase
{
    use ClearsSubscriptionStorage;
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const SPA_HEADERS = [
        'Origin' => 'http://localhost:3000',
        'X-Socket-ID' => '1234.5678',
    ];

    private const SUBSCRIBE = /** @lang GraphQL */ '
        subscription ($sessionId: ID!) {
            sessionFeedbackReady(sessionId: $sessionId) {
                id
                status
                mistakes {
                    id
                    userUtterance
                    correction
                    explanation
                }
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrammarPointSeeder::class);
    }

    public function test_the_subscription_response_names_the_channel_to_listen_on(): void
    {
        $user = User::factory()->create();
        $session = VoiceSession::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->graphQL(self::SUBSCRIBE, ['sessionId' => (string) $session->id], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree();

        $this->assertNotNull($response->json('extensions.lighthouse_subscriptions.channel'));
    }

    public function test_the_owner_may_authorize_the_channel_of_their_own_session(): void
    {
        $user = User::factory()->create();
        $session = VoiceSession::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $channel = $this->channelFor($user, $session);

        $this->postJson('/graphql/subscriptions/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ], self::SPA_HEADERS)->assertOk();
    }

    public function test_another_learner_may_not_authorize_that_channel(): void
    {
        $owner = User::factory()->create();
        $session = VoiceSession::factory()->for($owner)->create();
        Sanctum::actingAs($owner);

        $channel = $this->channelFor($owner, $session);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/graphql/subscriptions/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ], self::SPA_HEADERS)->assertForbidden();
    }

    public function test_a_guest_may_not_authorize_the_channel(): void
    {
        $owner = User::factory()->create();
        $session = VoiceSession::factory()->for($owner)->create();
        Sanctum::actingAs($owner);

        $channel = $this->channelFor($owner, $session);

        $this->app['auth']->forgetGuards();

        $this->postJson('/graphql/subscriptions/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ], self::SPA_HEADERS)->assertForbidden();
    }

    public function test_the_authorization_response_is_reachable_from_the_spa_origin(): void
    {
        $user = User::factory()->create();
        $session = VoiceSession::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $channel = $this->channelFor($user, $session);

        $this->postJson('/graphql/subscriptions/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ], self::SPA_HEADERS)
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    }

    public function test_the_broadcast_payload_carries_the_session_and_its_mistakes(): void
    {
        $user = User::factory()->create();
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Completed,
        ]);
        Mistake::factory()->for($session, 'session')->for($user)->create([
            'user_utterance' => 'He go home',
            'correction' => 'He goes home',
            'explanation' => 'Agreement rule',
        ]);

        Sanctum::actingAs($user);

        // Register the subscription in Redis
        $this->channelFor($user, $session);

        $driverManager = Mockery::mock(BroadcastDriverManager::class);
        $driverManager->shouldReceive('broadcast')
            ->once()
            ->with(
                Mockery::type(Subscriber::class),
                Mockery::on(function (array $result) use ($session): bool {
                    $feedback = $result['data']['sessionFeedbackReady'] ?? null;

                    return is_array($feedback)
                        && ($feedback['id'] ?? null) === (string) $session->id
                        && ($feedback['status'] ?? null) === 'completed'
                        && count($feedback['mistakes'] ?? []) === 1
                        && ($feedback['mistakes'][0]['userUtterance'] ?? null) === 'He go home';
                }),
            );

        $this->app->instance(BroadcastDriverManager::class, $driverManager);

        $subscriptionBroadcaster = $this->app->make(BroadcastsSubscriptions::class);
        $subscriptionBroadcaster->broadcast(new SessionFeedbackReady, 'sessionFeedbackReady', $session);
    }

    private function channelFor(User $user, VoiceSession $session): string
    {
        $response = $this->graphQL(self::SUBSCRIBE, ['sessionId' => (string) $session->id], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree();

        return (string) $response->json('extensions.lighthouse_subscriptions.channel');
    }
}
