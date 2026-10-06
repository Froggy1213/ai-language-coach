<?php

namespace Tests\Feature\Observability;

use App\Enums\VoiceSessionStatus;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Plan §7 pairs the AWS Budget alert with a per-learner voice-minutes tracker:
 * the alert says the account spent money, this report says whose calls it was.
 *
 * The report is read as decoded JSON rather than matched line by line, so the
 * test fails on a wrong number instead of on a moved colon.
 */
class VoiceUsageReportCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_reports_minutes_per_learner_and_flags_the_budget(): void
    {
        config(['observability.voice_minutes.monthly_budget_per_user' => 10]);

        $heavy = User::factory()->create(['email' => 'heavy@example.com']);
        $light = User::factory()->create(['email' => 'light@example.com']);

        VoiceSession::factory()->for($heavy)->create([
            'duration_sec' => 600,
            'created_at' => Carbon::now('UTC')->subDay(),
        ]);
        VoiceSession::factory()->for($heavy)->create([
            'duration_sec' => 300,
            'created_at' => Carbon::now('UTC')->subDays(2),
        ]);
        VoiceSession::factory()->for($light)->create([
            'duration_sec' => 60,
            'created_at' => Carbon::now('UTC')->subDay(),
        ]);

        $report = $this->report(['--days' => 30]);

        $this->assertSame(30, $report['window_days']);
        $this->assertSame(10, $report['window_minutes_budget']);

        $byEmail = collect($report['users'])->keyBy('email');

        $this->assertSame(2, $byEmail['heavy@example.com']['sessions']);
        // JSON has no float type, so 15.0 comes back as an int: compare numerically.
        $this->assertEqualsWithDelta(15.0, (float) $byEmail['heavy@example.com']['minutes'], 0.001);
        $this->assertTrue($byEmail['heavy@example.com']['over_budget']);

        $this->assertSame(1, $byEmail['light@example.com']['sessions']);
        $this->assertEqualsWithDelta(1.0, (float) $byEmail['light@example.com']['minutes'], 0.001);
        $this->assertFalse($byEmail['light@example.com']['over_budget']);
    }

    public function test_sessions_older_than_the_window_are_left_out(): void
    {
        $user = User::factory()->create(['email' => 'long-ago@example.com']);

        VoiceSession::factory()->for($user)->create([
            'duration_sec' => 600,
            'created_at' => Carbon::now('UTC')->subDays(40),
        ]);

        $report = $this->report(['--days' => 30]);

        $this->assertSame([], $report['users']);
    }

    public function test_a_failed_session_counts_as_usage_without_adding_minutes(): void
    {
        $user = User::factory()->create(['email' => 'unlucky@example.com']);

        VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Failed,
            'fail_reason' => 'llm_failed',
            'duration_sec' => null,
            'created_at' => Carbon::now('UTC')->subDay(),
        ]);

        $report = $this->report(['--days' => 30]);

        $this->assertCount(1, $report['users']);
        $this->assertSame(1, $report['users'][0]['sessions']);
        $this->assertEqualsWithDelta(0.0, (float) $report['users'][0]['minutes'], 0.001);
        $this->assertFalse($report['users'][0]['over_budget']);
    }

    public function test_the_window_budget_is_scaled_from_the_monthly_one(): void
    {
        config(['observability.voice_minutes.monthly_budget_per_user' => 30]);

        $report = $this->report(['--days' => 10]);

        $this->assertSame(10, $report['window_days']);
        $this->assertSame(10, $report['window_minutes_budget']);
    }

    public function test_the_table_form_reports_the_same_numbers_without_json(): void
    {
        $user = User::factory()->create(['email' => 'table@example.com']);

        VoiceSession::factory()->for($user)->create([
            'duration_sec' => 600,
            'created_at' => Carbon::now('UTC')->subDay(),
        ]);

        $exitCode = Artisan::call('voice:usage-report', ['--days' => 30]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('table@example.com', $output);
        $this->assertStringContainsString('10', $output);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function report(array $options): array
    {
        $exitCode = Artisan::call('voice:usage-report', $options + ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded, 'The command must print one JSON document.');

        return $decoded;
    }
}
