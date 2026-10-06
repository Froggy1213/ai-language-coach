<?php

namespace Tests\Unit\Mistakes;

use App\Enums\VoiceSessionStatus;
use App\Mistakes\RecurringMistakeDetector;
use App\Models\GrammarPoint;
use App\Models\Mistake;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecurringMistakeDetectorTest extends TestCase
{
    use LazilyRefreshDatabase;

    private RecurringMistakeDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new RecurringMistakeDetector;
    }

    public function test_two_distinct_sessions_is_below_default_threshold_of_three(): void
    {
        $now = Carbon::parse('2026-10-06 12:00:00');
        $user = User::factory()->create();
        $grammarPoint = GrammarPoint::factory()->create();

        $session1 = VoiceSession::factory()->for($user)->create();
        $session2 = VoiceSession::factory()->for($user)->create();

        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $session1->id,
            'created_at' => $now->copy()->subDays(2),
        ]);
        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $session2->id,
            'created_at' => $now->copy()->subDay(),
        ]);

        $results = $this->detector->detect($user, $now);

        $this->assertTrue($results->isEmpty());
    }

    public function test_three_distinct_sessions_is_at_threshold_and_detected(): void
    {
        $now = Carbon::parse('2026-10-06 12:00:00');
        $user = User::factory()->create();
        $grammarPoint = GrammarPoint::factory()->create();

        $session1 = VoiceSession::factory()->for($user)->create();
        $session2 = VoiceSession::factory()->for($user)->create();
        $session3 = VoiceSession::factory()->for($user)->create();

        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $session1->id,
            'created_at' => $now->copy()->subDays(3),
        ]);
        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $session2->id,
            'created_at' => $now->copy()->subDays(2),
        ]);
        $latest = Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $session3->id,
            'created_at' => $now->copy()->subDay(),
        ]);

        $results = $this->detector->detect($user, $now);

        $this->assertCount(1, $results);
        $recurring = $results->first();
        $this->assertSame($grammarPoint->id, $recurring->grammarPointId);
        $this->assertSame($grammarPoint->id, $recurring->grammarPoint->id);
        $this->assertSame(3, $recurring->sessionCount);
        $this->assertSame(3, $recurring->mistakeCount);
        $this->assertSame($latest->created_at->format('Y-m-d H:i:s'), $recurring->lastMistakeAt->format('Y-m-d H:i:s'));
    }

    public function test_five_mistakes_in_one_session_count_as_session_count_one(): void
    {
        $now = Carbon::parse('2026-10-06 12:00:00');
        $user = User::factory()->create();
        $grammarPoint = GrammarPoint::factory()->create();

        $session = VoiceSession::factory()->for($user)->create();

        // 5 mistakes within the exact same session (the entire point of COUNT DISTINCT session_id).
        Mistake::factory()->for($user)->for($grammarPoint)->count(5)->create([
            'session_id' => $session->id,
            'created_at' => $now->copy()->subDay(),
        ]);

        $results = $this->detector->detect($user, $now);

        // sessionCount is 1, below the threshold of 3, so it must not be flagged.
        $this->assertTrue($results->isEmpty());
    }

    public function test_two_different_grammar_points_do_not_merge(): void
    {
        $now = Carbon::parse('2026-10-06 12:00:00');
        $user = User::factory()->create();
        $gp1 = GrammarPoint::factory()->create();
        $gp2 = GrammarPoint::factory()->create();

        $sessions = VoiceSession::factory()->for($user)->count(3)->create();

        // gp1 has 3 distinct sessions (reaches threshold).
        foreach ($sessions as $session) {
            Mistake::factory()->for($user)->for($gp1)->create([
                'session_id' => $session->id,
                'created_at' => $now->copy()->subDays(1),
            ]);
        }

        // gp2 has only 2 distinct sessions (below threshold).
        Mistake::factory()->for($user)->for($gp2)->create([
            'session_id' => $sessions[0]->id,
            'created_at' => $now->copy()->subDays(1),
        ]);
        Mistake::factory()->for($user)->for($gp2)->create([
            'session_id' => $sessions[1]->id,
            'created_at' => $now->copy()->subDays(1),
        ]);

        $results = $this->detector->detect($user, $now);

        $this->assertCount(1, $results);
        $this->assertSame($gp1->id, $results->first()->grammarPointId);
    }

    public function test_mistakes_older_than_the_window_do_not_count_while_recent_ones_do(): void
    {
        $now = Carbon::parse('2026-10-15 12:00:00');
        $user = User::factory()->create();
        $grammarPoint = GrammarPoint::factory()->create();

        // 2 mistakes outside the 7-day rolling window (10 and 8 days old).
        $oldSession1 = VoiceSession::factory()->for($user)->create();
        $oldSession2 = VoiceSession::factory()->for($user)->create();
        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $oldSession1->id,
            'created_at' => $now->copy()->subDays(10),
        ]);
        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $oldSession2->id,
            'created_at' => $now->copy()->subDays(8),
        ]);

        // 2 mistakes inside the 7-day window.
        $recentSession1 = VoiceSession::factory()->for($user)->create();
        $recentSession2 = VoiceSession::factory()->for($user)->create();
        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $recentSession1->id,
            'created_at' => $now->copy()->subDays(3),
        ]);
        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $recentSession2->id,
            'created_at' => $now->copy()->subDay(),
        ]);

        // Total sessions overall = 4, but only 2 inside the rolling 7-day window.
        $results = $this->detector->detect($user, $now);
        $this->assertTrue($results->isEmpty());

        // Adding a 3rd distinct session inside the window pushes it across threshold.
        $recentSession3 = VoiceSession::factory()->for($user)->create();
        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $recentSession3->id,
            'created_at' => $now->copy()->subHours(2),
        ]);

        $updatedResults = $this->detector->detect($user, $now);
        $this->assertCount(1, $updatedResults);
        $this->assertSame(3, $updatedResults->first()->sessionCount);
    }

    public function test_mistakes_exactly_at_the_window_boundary_behave_deterministically(): void
    {
        // Design choice: The rolling window uses an inclusive lower bound
        // `created_at >= ($now - 7 days)`, adhering to plan §3 SQL
        // `created_at >= NOW() - INTERVAL 7 DAY`.
        // Therefore, a mistake timestamped at exactly `$now - 7 days` is included,
        // while a mistake 1 second earlier is excluded.
        $now = Carbon::parse('2026-10-10 12:00:00');
        $user = User::factory()->create();
        $grammarPoint = GrammarPoint::factory()->create();

        $windowStart = $now->copy()->subDays(7); // 2026-10-03 12:00:00

        // 1 mistake exactly 1 second before the boundary (excluded).
        $preBoundarySession = VoiceSession::factory()->for($user)->create();
        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $preBoundarySession->id,
            'created_at' => $windowStart->copy()->subSecond(),
        ]);

        // 2 mistakes comfortably inside the window.
        $inSession1 = VoiceSession::factory()->for($user)->create();
        $inSession2 = VoiceSession::factory()->for($user)->create();
        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $inSession1->id,
            'created_at' => $now->copy()->subDays(2),
        ]);
        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $inSession2->id,
            'created_at' => $now->copy()->subDay(),
        ]);

        // At this point, only 2 sessions in window: pre-boundary was excluded.
        $this->assertTrue($this->detector->detect($user, $now)->isEmpty());

        // Now add a mistake timestamped EXACTLY at the boundary ($windowStart).
        $boundarySession = VoiceSession::factory()->for($user)->create();
        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $boundarySession->id,
            'created_at' => $windowStart,
        ]);

        // The boundary mistake is included, bringing distinct session count to 3.
        $results = $this->detector->detect($user, $now);
        $this->assertCount(1, $results);
        $this->assertSame(3, $results->first()->sessionCount);
    }

    public function test_per_user_isolation(): void
    {
        $now = Carbon::parse('2026-10-06 12:00:00');
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $grammarPoint = GrammarPoint::factory()->create();

        // User A has 2 distinct sessions.
        $sessionsA = VoiceSession::factory()->for($userA)->count(2)->create();
        foreach ($sessionsA as $session) {
            Mistake::factory()->for($userA)->for($grammarPoint)->create([
                'session_id' => $session->id,
                'created_at' => $now->copy()->subDay(),
            ]);
        }

        // User B has 2 distinct sessions.
        $sessionsB = VoiceSession::factory()->for($userB)->count(2)->create();
        foreach ($sessionsB as $session) {
            Mistake::factory()->for($userB)->for($grammarPoint)->create([
                'session_id' => $session->id,
                'created_at' => $now->copy()->subDay(),
            ]);
        }

        // Neither user has reached threshold 3; neither user's mistakes inflate the other.
        $this->assertTrue($this->detector->detect($userA, $now)->isEmpty());
        $this->assertTrue($this->detector->detect($userB, $now)->isEmpty());
    }

    public function test_ordering_is_by_session_count_desc_then_last_mistake_at_desc(): void
    {
        $now = Carbon::parse('2026-10-06 12:00:00');
        $user = User::factory()->create();

        $gp1 = GrammarPoint::factory()->create(['title' => 'Highest sessions']);
        $gp2 = GrammarPoint::factory()->create(['title' => 'Tied sessions, newer mistake']);
        $gp3 = GrammarPoint::factory()->create(['title' => 'Tied sessions, older mistake']);

        // gp1: 4 distinct sessions, last mistake at 2026-10-05 10:00:00.
        $sessionsGp1 = VoiceSession::factory()->for($user)->count(4)->create();
        foreach ($sessionsGp1 as $index => $session) {
            Mistake::factory()->for($user)->for($gp1)->create([
                'session_id' => $session->id,
                'created_at' => Carbon::parse('2026-10-05 10:00:00')->subHours($index),
            ]);
        }

        // gp2: 3 distinct sessions, last mistake at 2026-10-06 11:00:00 (more recent).
        $sessionsGp2 = VoiceSession::factory()->for($user)->count(3)->create();
        foreach ($sessionsGp2 as $index => $session) {
            Mistake::factory()->for($user)->for($gp2)->create([
                'session_id' => $session->id,
                'created_at' => Carbon::parse('2026-10-06 11:00:00')->subHours($index),
            ]);
        }

        // gp3: 3 distinct sessions, last mistake at 2026-10-04 09:00:00 (older).
        $sessionsGp3 = VoiceSession::factory()->for($user)->count(3)->create();
        foreach ($sessionsGp3 as $index => $session) {
            Mistake::factory()->for($user)->for($gp3)->create([
                'session_id' => $session->id,
                'created_at' => Carbon::parse('2026-10-04 09:00:00')->subHours($index),
            ]);
        }

        $results = $this->detector->detect($user, $now);

        $this->assertCount(3, $results);
        $this->assertSame($gp1->id, $results[0]->grammarPointId);
        $this->assertSame(4, $results[0]->sessionCount);

        $this->assertSame($gp2->id, $results[1]->grammarPointId);
        $this->assertSame(3, $results[1]->sessionCount);
        $this->assertSame('2026-10-06 11:00:00', $results[1]->lastMistakeAt->format('Y-m-d H:i:s'));

        $this->assertSame($gp3->id, $results[2]->grammarPointId);
        $this->assertSame(3, $results[2]->sessionCount);
        $this->assertSame('2026-10-04 09:00:00', $results[2]->lastMistakeAt->format('Y-m-d H:i:s'));
    }

    public function test_mistake_count_counts_rows_while_session_count_counts_sessions(): void
    {
        $now = Carbon::parse('2026-10-06 12:00:00');
        $user = User::factory()->create();
        $grammarPoint = GrammarPoint::factory()->create();

        $session1 = VoiceSession::factory()->for($user)->create();
        $session2 = VoiceSession::factory()->for($user)->create();
        $session3 = VoiceSession::factory()->for($user)->create();

        // Session 1: 3 mistakes
        Mistake::factory()->for($user)->for($grammarPoint)->count(3)->create([
            'session_id' => $session1->id,
            'created_at' => $now->copy()->subDays(3),
        ]);
        // Session 2: 2 mistakes
        Mistake::factory()->for($user)->for($grammarPoint)->count(2)->create([
            'session_id' => $session2->id,
            'created_at' => $now->copy()->subDays(2),
        ]);
        // Session 3: 1 mistake
        Mistake::factory()->for($user)->for($grammarPoint)->count(1)->create([
            'session_id' => $session3->id,
            'created_at' => $now->copy()->subDay(),
        ]);

        $results = $this->detector->detect($user, $now);

        $this->assertCount(1, $results);
        $item = $results->first();
        $this->assertSame(3, $item->sessionCount);
        $this->assertSame(6, $item->mistakeCount);
    }

    public function test_sessions_in_any_status_count_towards_session_count(): void
    {
        // Design choice: Mistakes recorded during a session represent real learner
        // utterances and errors regardless of how the session terminated (completed,
        // abandoned by tab close / idle timeout, or failed due to agent worker crash).
        // The repeat-error query in plan §3 does not join voice_sessions.status.
        $now = Carbon::parse('2026-10-06 12:00:00');
        $user = User::factory()->create();
        $grammarPoint = GrammarPoint::factory()->create();

        $completedSession = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Completed,
        ]);
        $abandonedSession = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Abandoned,
        ]);
        $failedSession = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Failed,
        ]);

        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $completedSession->id,
            'created_at' => $now->copy()->subDays(3),
        ]);
        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $abandonedSession->id,
            'created_at' => $now->copy()->subDays(2),
        ]);
        Mistake::factory()->for($user)->for($grammarPoint)->create([
            'session_id' => $failedSession->id,
            'created_at' => $now->copy()->subDay(),
        ]);

        $results = $this->detector->detect($user, $now);

        $this->assertCount(1, $results);
        $this->assertSame(3, $results->first()->sessionCount);
    }

    public function test_a_learner_with_no_mistakes_returns_an_empty_collection(): void
    {
        $user = User::factory()->create();

        $results = $this->detector->detect($user);

        $this->assertTrue($results->isEmpty());
    }
}
