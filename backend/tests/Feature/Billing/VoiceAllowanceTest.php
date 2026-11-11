<?php

namespace Tests\Feature\Billing;

use App\Billing\VoiceAllowance;
use App\Billing\VoiceAllowanceResolver;
use App\Enums\LessonCardStatus;
use App\Enums\VoiceSessionStatus;
use App\Models\LessonCard;
use App\Models\Roadmap;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * The voice allowance: what counts, when it resets, and what the learner sees
 * when it runs out.
 */
class VoiceAllowanceTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const REQUEST_TOKEN = /** @lang GraphQL */ '
        mutation ($lessonCardId: ID!) {
            requestVoiceToken(lessonCardId: $lessonCardId) { id status }
        }
    ';

    private User $user;

    private LessonCard $card;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.free_seconds' => 900,
            'voice.livekit.url' => 'wss://livekit.test',
            'voice.livekit.public_url' => null,
            'voice.livekit.api_key' => 'test-api-key',
            'voice.livekit.api_secret' => 'test-api-secret-that-is-long-enough',
            'voice.max_session_seconds' => 900,
            // The daily cap is a separate guard with its own tests; this suite
            // is about paid minutes, so it must not trip first.
            'voice.daily_session_limit' => 0,
        ]);

        Http::preventStrayRequests();

        $this->user = User::factory()->create();
        $roadmap = Roadmap::factory()->for($this->user)->create();
        $this->card = LessonCard::factory()->for($roadmap)->create([
            'status' => LessonCardStatus::Ready,
        ]);
    }

    public function test_a_new_learner_has_fifteen_free_minutes(): void
    {
        $allowance = $this->allowance();

        $this->assertSame('free', $allowance->planCode);
        $this->assertSame(900, $allowance->includedSeconds);
        $this->assertSame(0, $allowance->usedSeconds);
        $this->assertSame(15, $allowance->remainingMinutes());
        $this->assertNull($allowance->resetsAt);
        $this->assertFalse($allowance->hasSubscription);
    }

    public function test_a_free_session_is_refused_once_the_allowance_is_spent(): void
    {
        $this->sessions(10, 90);
        Sanctum::actingAs($this->user);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $this->card->getKey()]);

        $this->assertSame('VOICE_MINUTES_EXHAUSTED', $response->json('errors.0.extensions.code'));
        $this->assertSame(900, $response->json('errors.0.extensions.usedSeconds'));
        $this->assertSame(900, $response->json('errors.0.extensions.includedSeconds'));
        $this->assertNull($response->json('errors.0.extensions.resetsAt'));

        // The refusal happens before LiveKit is touched: no room, no agent.
        Http::assertNothingSent();
    }

    public function test_a_paid_plan_raises_the_allowance_to_its_monthly_minutes(): void
    {
        Subscription::factory()->for($this->user)->forPlan('entry')->create();
        $this->sessions(10, 90);

        $this->assertSame('entry', $this->allowance()->planCode);
        $this->assertSame(150, $this->allowance()->includedMinutes());
        $this->assertSame(15, $this->allowance()->usedMinutes());
        $this->assertSame(135, $this->allowance()->remainingMinutes());
    }

    public function test_an_exhausted_paid_plan_is_refused_with_the_same_code(): void
    {
        Subscription::factory()->for($this->user)->forPlan('entry')->create();
        $this->sessions(10, 900);
        Sanctum::actingAs($this->user);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $this->card->getKey()]);

        $this->assertSame('VOICE_MINUTES_EXHAUSTED', $response->json('errors.0.extensions.code'));
        $this->assertSame(9000, $response->json('errors.0.extensions.usedSeconds'));
        $this->assertSame(9000, $response->json('errors.0.extensions.includedSeconds'));
        $this->assertNotNull($response->json('errors.0.extensions.resetsAt'));
    }

    public function test_a_past_due_subscription_is_refused_before_the_allowance(): void
    {
        Subscription::factory()->for($this->user)->forPlan('entry')->pastDue()->create();
        Sanctum::actingAs($this->user);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $this->card->getKey()]);

        $this->assertSame('VOICE_SUBSCRIPTION_INACTIVE', $response->json('errors.0.extensions.code'));
        $this->assertSame('past_due', $response->json('errors.0.extensions.status'));
    }

    public function test_only_sessions_inside_the_monthly_window_count(): void
    {
        Subscription::factory()->for($this->user)->forPlan('entry')->create([
            'usage_anchor_at' => now()->startOfMonth(),
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->startOfMonth()->addMonth(),
        ]);

        // 9000 seconds last month: it was covered by last month's allowance and
        // must not reduce this month's.
        VoiceSession::factory()->for($this->user)->for($this->card, 'lessonCard')->create([
            'status' => VoiceSessionStatus::Completed,
            'duration_sec' => 9000,
            'created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDay(),
        ]);

        $this->assertSame(0, $this->allowance()->usedSeconds);
        $this->assertSame(150, $this->allowance()->remainingMinutes());
    }

    public function test_a_yearly_subscription_still_resets_every_month(): void
    {
        $start = now()->startOfMonth();

        Subscription::factory()->for($this->user)->forPlan('pro', 'year')->create([
            'usage_anchor_at' => $start,
            'current_period_start' => $start,
            'current_period_end' => $start->copy()->addYear(),
        ]);

        $allowance = $this->allowance();

        $this->assertSame(700, $allowance->includedMinutes());
        $this->assertSame($start->copy()->addMonthNoOverflow()->toDateTimeString(), $allowance->resetsAt?->toDateTimeString());
    }

    public function test_a_subscription_whose_plan_vanished_falls_back_to_free(): void
    {
        Subscription::factory()->for($this->user)->create(['plan_code' => 'retired-tier']);

        $this->assertSame('free', $this->allowance()->planCode);
    }

    private function allowance(): VoiceAllowance
    {
        return $this->app->make(VoiceAllowanceResolver::class)->for($this->user->refresh());
    }

    /**
     * Completed sessions of the given duration, all inside the current window.
     */
    private function sessions(int $count, int $seconds): void
    {
        foreach (range(1, $count) as $ignored) {
            VoiceSession::factory()->for($this->user)->for($this->card, 'lessonCard')->create([
                'status' => VoiceSessionStatus::Completed,
                'duration_sec' => $seconds,
            ]);
        }
    }
}
