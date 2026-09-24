<?php

namespace Tests\Feature\GraphQL;

use App\GraphQL\Subscriptions\AssessmentReady;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Http\Request;
use Mockery;
use Nuwave\Lighthouse\Subscriptions\Subscriber;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class AssessmentReadySubscriptionTest extends TestCase
{
    use MakesGraphQLRequests;

    public function test_a_learner_may_watch_their_own_assessment(): void
    {
        $authorized = (new AssessmentReady)->authorize($this->subscriber('7'), $this->requestFor($this->user(7)));

        $this->assertTrue($authorized);
    }

    public function test_a_learner_cannot_watch_somebody_elses_assessment(): void
    {
        $authorized = (new AssessmentReady)->authorize($this->subscriber('8'), $this->requestFor($this->user(7)));

        $this->assertFalse($authorized);
    }

    public function test_a_guest_cannot_subscribe(): void
    {
        $authorized = (new AssessmentReady)->authorize($this->subscriber('7'), $this->requestFor(null));

        $this->assertFalse($authorized);
    }

    public function test_it_delivers_an_assessment_to_the_learner_it_belongs_to(): void
    {
        $subscription = new AssessmentReady;
        $assessment = (new Assessment)->forceFill(['user_id' => 7]);

        $this->assertTrue($subscription->filter($this->subscriber('7'), $assessment));
        $this->assertFalse($subscription->filter($this->subscriber('8'), $assessment));
    }

    public function test_it_ignores_a_root_that_is_not_an_assessment(): void
    {
        $this->assertFalse((new AssessmentReady)->filter($this->subscriber('7'), ['user_id' => 7]));
    }

    public function test_the_schema_keeps_the_subscription_field_nullable(): void
    {
        $type = $this->introspectType('Subscription');
        $this->assertNotNull($type);

        $field = collect($type['fields'])->firstWhere('name', 'assessmentReady');
        $this->assertNotNull($field, 'The Subscription type does not declare `assessmentReady`.');

        // Nullable on purpose: at subscribe time the field resolves to null and
        // a non-null type would reject the subscription response (plan §5).
        $this->assertSame('Assessment', $field['type']['name']);
        $this->assertSame('userId', $field['args'][0]['name']);
        $this->assertSame('NON_NULL', $field['args'][0]['type']['kind']);
        $this->assertSame('ID', $field['args'][0]['type']['ofType']['name']);
    }

    private function subscriber(string $userId): Subscriber
    {
        $subscriber = Mockery::mock(Subscriber::class);
        $subscriber->args = ['userId' => $userId];

        return $subscriber;
    }

    private function requestFor(?User $user): Request
    {
        $request = Request::create('/graphql/subscriptions/auth');
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    }

    private function user(int $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }
}
