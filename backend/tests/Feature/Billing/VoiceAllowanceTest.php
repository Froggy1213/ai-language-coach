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
use Carbon\CarbonImmutable;
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
            'billing.free_seconds' => 1800,
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

    public function test_a_new_learner_has_thirty_free_minutes_every_month(): void
    {
        $allowance = $this->allowance();

        $this->assertSame('free', $allowance->planCode);
        $this->assertSame(1800, $allowance->includedSeconds);
        $this->assertSame(0, $allowance->usedSeconds);
        $this->assertSame(30, $allowance->remainingMinutes());
        $this->assertNotNull($allowance->resetsAt);
        $this->assertFalse($allowance->hasSubscription);
    }

    public function test_a_free_session_is_refused_once_the_allowance_is_spent(): void
    {
        $this->sessions(20, 90);
        Sanctum::actingAs($this->user);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $this->card->getKey()]);

        $this->assertSame('VOICE_MINUTES_EXHAUSTED', $response->json('errors.0.extensions.code'));
        $this->assertSame(1800, $response->json('errors.0.extensions.usedSeconds'));
        $this->assertSame(1800, $response->json('errors.0.extensions.includedSeconds'));
        $this->assertNotNull($response->json('errors.0.extensions.resetsAt'));

        // The refusal happens before LiveKit is touched: no room, no agent.
        Http::assertNothingSent();
    }

    public function test_only_sessions_inside_the_free_monthly_window_count(): void
    {
        // Last month's free minutes are last month's: spending the whole 30
        // minutes then must not leave this month's allowance empty.
        VoiceSession::factory()->for($this->user)->for($this->card, 'lessonCard')->create([
            'status' => VoiceSessionStatus::Completed,
            'duration_sec' => 1800,
            'created_at' => now()->subMonthNoOverflow(),
        ]);

        $this->assertSame(0, $this->allowance()->usedSeconds);
        $this->assertSame(30, $this->allowance()->remainingMinutes());
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

    public function test_end_of_month_anchors_preserve_future_resets_and_count_in_window_sessions(): void
    {
        $janAnchor = CarbonImmutable::parse('2026-01-31 08:00:00', 'UTC');
        $this->user->forceFill([
            'created_at' => $janAnchor,
            'updated_at' => $janAnchor,
        ])->saveQuietly();

        // Outside the Feb 28..Mar 31 window (before Feb 28 08:00), inside Feb window.
        VoiceSession::factory()->for($this->user)->create([
            'status' => VoiceSessionStatus::Completed,
            'created_at' => '2026-02-15 12:00:00',
            'duration_sec' => 300,
        ]);
        // Inside [2026-02-28 08:00, 2026-03-31 08:00).
        VoiceSession::factory()->for($this->user)->create([
            'status' => VoiceSessionStatus::Completed,
            'created_at' => '2026-02-28 09:00:00',
            'duration_sec' => 420,
        ]);
        // Inside [2026-03-31 08:00, 2026-04-30 08:00).
        VoiceSession::factory()->for($this->user)->create([
            'status' => VoiceSessionStatus::Completed,
            'created_at' => '2026-03-31 10:00:00',
            'duration_sec' => 180,
        ]);

        // 2026-02-28 12:00 UTC: after Feb 28 08:00 -> resets 2026-03-31 08:00 UTC.
        $this->travelTo(CarbonImmutable::parse('2026-02-28 12:00:00', 'UTC'));
        $feb = $this->allowance();
        $this->assertTrue($feb->resetsAt->greaterThan(now()));
        $this->assertSame('2026-03-31 08:00:00', $feb->resetsAt->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(420, $feb->usedSeconds);

        // 2026-03-30 12:00 UTC: still inside [2026-02-28 08:00, 2026-03-31 08:00).
        $this->travelTo(CarbonImmutable::parse('2026-03-30 12:00:00', 'UTC'));
        $mar30 = $this->allowance();
        $this->assertTrue($mar30->resetsAt->greaterThan(now()));
        $this->assertSame('2026-03-31 08:00:00', $mar30->resetsAt->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(420, $mar30->usedSeconds);

        // 2026-03-31 12:00 UTC: moved into [2026-03-31 08:00, 2026-04-30 08:00).
        $this->travelTo(CarbonImmutable::parse('2026-03-31 12:00:00', 'UTC'));
        $mar31 = $this->allowance();
        $this->assertTrue($mar31->resetsAt->greaterThan(now()));
        $this->assertSame('2026-04-30 08:00:00', $mar31->resetsAt->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(180, $mar31->usedSeconds);

        // 2026-03-31 anchor evaluated at 2026-05-30 12:00 UTC -> resets 2026-05-31 08:00 UTC.
        $marAnchor = CarbonImmutable::parse('2026-03-31 08:00:00', 'UTC');
        $this->user->forceFill([
            'created_at' => $marAnchor,
            'updated_at' => $marAnchor,
        ])->saveQuietly();
        VoiceSession::factory()->for($this->user)->create([
            'status' => VoiceSessionStatus::Completed,
            'created_at' => '2026-05-15 12:00:00',
            'duration_sec' => 240,
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-05-30 12:00:00', 'UTC'));
        $may30 = $this->allowance();
        $this->assertTrue($may30->resetsAt->greaterThan(now()));
        $this->assertSame('2026-05-31 08:00:00', $may30->resetsAt->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(240, $may30->usedSeconds);
    }

    public function test_terminal_subscriptions_fall_back_to_free_and_active_beats_newer_inactive_row(): void
    {
        Subscription::factory()->for($this->user)->forPlan('entry')->canceled()->create([
            'stripe_subscription_id' => 'sub_canceled',
        ]);

        $canceledAllowance = $this->allowance();
        $this->assertSame('free', $canceledAllowance->planCode);
        $this->assertSame(1800, $canceledAllowance->includedSeconds);
        $this->assertFalse($canceledAllowance->hasSubscription);

        Subscription::factory()->for($this->user)->forPlan('entry')->create([
            'stripe_subscription_id' => 'sub_expired',
            'status' => 'incomplete_expired',
        ]);

        $expiredAllowance = $this->allowance();
        $this->assertSame('free', $expiredAllowance->planCode);
        $this->assertSame(1800, $expiredAllowance->includedSeconds);
        $this->assertFalse($expiredAllowance->hasSubscription);

        // An older active subscription must not be shadowed by a newer canceled/incomplete row.
        $active = Subscription::factory()->for($this->user)->forPlan('standard')->create([
            'stripe_subscription_id' => 'sub_active',
            'status' => 'active',
            'created_at' => now()->subDays(5),
        ]);
        Subscription::factory()->for($this->user)->forPlan('entry')->canceled()->create([
            'stripe_subscription_id' => 'sub_newer_canceled',
            'created_at' => now()->subMinute(),
        ]);

        $resolved = $this->allowance();
        $this->assertSame('standard', $resolved->planCode);
        $this->assertTrue($resolved->hasSubscription);
        $this->assertNotSame('free', $resolved->planCode);
        $this->assertSame($active->plan_code, $resolved->planCode);
    }

    public function test_unpaid_subscription_is_rejected_as_inactive_when_starting_voice_session(): void
    {
        Subscription::factory()->for($this->user)->forPlan('entry')->create([
            'status' => 'unpaid',
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $this->card->getKey()]);
        $this->assertSame('VOICE_SUBSCRIPTION_INACTIVE', $response->json('errors.0.extensions.code'));
    }

    public function test_exhausted_error_extensions_include_plan_code_and_subscription_flag(): void
    {
        $this->sessions(2, 900);

        Sanctum::actingAs($this->user);

        $freeResponse = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $this->card->getKey()]);
        $this->assertSame('VOICE_MINUTES_EXHAUSTED', $freeResponse->json('errors.0.extensions.code'));
        $this->assertSame('free', $freeResponse->json('errors.0.extensions.planCode'));
        $this->assertFalse($freeResponse->json('errors.0.extensions.hasSubscription'));

        $limitSeconds = (int) config('billing.plans.entry.monthly_seconds');
        Subscription::factory()->for($this->user)->forPlan('entry')->create([
            'status' => 'active',
            'usage_anchor_at' => now()->subDay(),
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addDays(29),
        ]);
        VoiceSession::factory()->for($this->user)->for($this->card, 'lessonCard')->create([
            'status' => VoiceSessionStatus::Completed,
            'created_at' => now(),
            'duration_sec' => $limitSeconds,
        ]);

        $paidResponse = $this->graphQL(self::REQUEST_TOKEN, ['lessonCardId' => $this->card->getKey()]);
        $this->assertSame('VOICE_MINUTES_EXHAUSTED', $paidResponse->json('errors.0.extensions.code'));
        $this->assertSame('entry', $paidResponse->json('errors.0.extensions.planCode'));
        $this->assertTrue($paidResponse->json('errors.0.extensions.hasSubscription'));
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
