<?php

namespace Tests\Feature\GraphQL;

use App\GraphQL\Subscriptions\SessionFeedbackReady;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Nuwave\Lighthouse\Subscriptions\Subscriber;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class SessionFeedbackReadySubscriptionTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    public function test_a_learner_may_watch_their_own_session(): void
    {
        $user = User::factory()->create();
        $session = VoiceSession::factory()->for($user)->create();

        $authorized = (new SessionFeedbackReady)->authorize(
            $this->subscriber((string) $session->id),
            $this->requestFor($user),
        );

        $this->assertTrue($authorized);
    }

    public function test_a_learner_cannot_watch_somebody_elses_session(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $session = VoiceSession::factory()->for($owner)->create();

        $authorized = (new SessionFeedbackReady)->authorize(
            $this->subscriber((string) $session->id),
            $this->requestFor($otherUser),
        );

        $this->assertFalse($authorized);
    }

    public function test_a_guest_cannot_subscribe(): void
    {
        $user = User::factory()->create();
        $session = VoiceSession::factory()->for($user)->create();

        $authorized = (new SessionFeedbackReady)->authorize(
            $this->subscriber((string) $session->id),
            $this->requestFor(null),
        );

        $this->assertFalse($authorized);
    }

    public function test_it_delivers_a_session_to_the_subscriber_listening_to_it(): void
    {
        $subscription = new SessionFeedbackReady;
        $session = (new VoiceSession)->forceFill(['id' => 42]);

        $this->assertTrue($subscription->filter($this->subscriber('42'), $session));
        $this->assertFalse($subscription->filter($this->subscriber('43'), $session));
    }

    public function test_it_ignores_a_root_that_is_not_a_voice_session(): void
    {
        $this->assertFalse((new SessionFeedbackReady)->filter($this->subscriber('42'), ['id' => 42]));
    }

    public function test_the_schema_keeps_the_subscription_field_nullable(): void
    {
        $type = $this->introspectType('Subscription');
        $this->assertNotNull($type);

        $field = collect($type['fields'])->firstWhere('name', 'sessionFeedbackReady');
        $this->assertNotNull($field, 'The Subscription type does not declare `sessionFeedbackReady`.');

        // Nullable on purpose: at subscribe time the field resolves to null and
        // a non-null type would reject the subscription response (plan §5).
        $this->assertSame('VoiceSession', $field['type']['name']);
        $this->assertSame('sessionId', $field['args'][0]['name']);
        $this->assertSame('NON_NULL', $field['args'][0]['type']['kind']);
        $this->assertSame('ID', $field['args'][0]['type']['ofType']['name']);
    }

    private function subscriber(string $sessionId): Subscriber
    {
        $subscriber = Mockery::mock(Subscriber::class);
        $subscriber->args = ['sessionId' => $sessionId];

        return $subscriber;
    }

    private function requestFor(?User $user): Request
    {
        $request = Request::create('/graphql/subscriptions/auth');
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    }
}
