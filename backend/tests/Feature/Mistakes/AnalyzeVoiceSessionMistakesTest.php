<?php

namespace Tests\Feature\Mistakes;

use App\Enums\VoiceSessionStatus;
use App\GraphQL\Subscriptions\SessionFeedbackReady;
use App\Mistakes\AnalyzedMistake;
use App\Mistakes\AnalyzeVoiceSessionMistakes;
use App\Mistakes\GoalReviewer;
use App\Mistakes\GoalReviewResult;
use App\Mistakes\MistakeAnalysisFailed;
use App\Mistakes\MistakeAnalysisResult;
use App\Mistakes\MistakeAnalyzer;
use App\Models\GrammarPoint;
use App\Models\ReviewItem;
use App\Models\User;
use App\Models\VoiceSession;
use Database\Seeders\GrammarPointSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Mockery;
use Nuwave\Lighthouse\Subscriptions\Contracts\BroadcastsSubscriptions;
use Tests\TestCase;

class AnalyzeVoiceSessionMistakesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrammarPointSeeder::class);
    }

    public function test_it_writes_mistakes_creates_review_items_and_broadcasts_subscription(): void
    {
        Carbon::setTestNow('2026-10-02 12:00:00');

        $user = User::factory()->create(['target_language' => 'en']);
        $presentSimple = GrammarPoint::query()->where('language', 'en')->where('code', 'present_simple')->firstOrFail();
        $pastSimple = GrammarPoint::query()->where('language', 'en')->where('code', 'past_simple')->firstOrFail();

        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'He go to school yesterday.'],
                ['turn_id' => 't2', 'transcript' => 'I seen him there.'],
            ],
        ]);

        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldReceive('analyze')
            ->once()
            ->with("He go to school yesterday.\nI seen him there.", Mockery::on(static fn ($u): bool => $u->is($user)))
            ->andReturn(new MistakeAnalysisResult([
                new AnalyzedMistake(
                    grammarPoint: $presentSimple,
                    userUtterance: 'He go to school',
                    correction: 'He went to school',
                    explanation: 'Past simple required for yesterday.',
                ),
                new AnalyzedMistake(
                    grammarPoint: $pastSimple,
                    userUtterance: 'I seen him',
                    correction: 'I saw him',
                    explanation: 'Saw is the simple past form.',
                ),
            ]));

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts);

        // Verify mistakes written
        $this->assertDatabaseCount('mistakes', 2);
        $this->assertDatabaseHas('mistakes', [
            'session_id' => $session->id,
            'user_id' => $user->id,
            'grammar_point_id' => $presentSimple->id,
            'user_utterance' => 'He go to school',
            'correction' => 'He went to school',
            'explanation' => 'Past simple required for yesterday.',
        ]);
        $this->assertDatabaseHas('mistakes', [
            'session_id' => $session->id,
            'user_id' => $user->id,
            'grammar_point_id' => $pastSimple->id,
            'user_utterance' => 'I seen him',
            'correction' => 'I saw him',
            'explanation' => 'Saw is the simple past form.',
        ]);

        // Verify review_items created lazily with SM-2 defaults
        $this->assertDatabaseCount('review_items', 2);
        $this->assertDatabaseHas('review_items', [
            'user_id' => $user->id,
            'grammar_point_id' => $presentSimple->id,
            'ease_factor' => '2.50',
            'interval_days' => 1,
            'repetition_number' => 0,
            'next_review_at' => '2026-10-03 12:00:00',
        ]);
        $this->assertDatabaseHas('review_items', [
            'user_id' => $user->id,
            'grammar_point_id' => $pastSimple->id,
            'ease_factor' => '2.50',
            'interval_days' => 1,
            'repetition_number' => 0,
            'next_review_at' => '2026-10-03 12:00:00',
        ]);

        // Verify subscription broadcast
        $broadcasts->shouldHaveReceived('broadcast')
            ->once()
            ->with(
                Mockery::type(SessionFeedbackReady::class),
                'sessionFeedbackReady',
                Mockery::on(static fn ($root): bool => $root instanceof VoiceSession && $root->id === $session->id),
            );

        Carbon::setTestNow();
    }

    public function test_it_does_not_overwrite_existing_review_items_for_the_user(): void
    {
        Carbon::setTestNow('2026-10-02 12:00:00');

        $user = User::factory()->create(['target_language' => 'en']);
        $presentSimple = GrammarPoint::query()->where('language', 'en')->where('code', 'present_simple')->firstOrFail();

        // User already reviewed this item before and has custom SM-2 schedule
        ReviewItem::factory()->for($user)->for($presentSimple)->create([
            'ease_factor' => 2.10,
            'interval_days' => 6,
            'repetition_number' => 2,
            'next_review_at' => Carbon::parse('2026-10-10 12:00:00'),
        ]);

        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'He go.'],
            ],
        ]);

        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->andReturn(new MistakeAnalysisResult([
            new AnalyzedMistake(
                grammarPoint: $presentSimple,
                userUtterance: 'He go',
                correction: 'He goes',
                explanation: 'Subject-verb agreement',
            ),
        ]));

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts);

        // Review item should keep existing SM-2 schedule intact
        $this->assertDatabaseCount('review_items', 1);
        $this->assertDatabaseHas('review_items', [
            'user_id' => $user->id,
            'grammar_point_id' => $presentSimple->id,
            'ease_factor' => '2.10',
            'interval_days' => 6,
            'repetition_number' => 2,
            'next_review_at' => '2026-10-10 12:00:00',
        ]);

        Carbon::setTestNow();
    }

    public function test_a_rerun_does_not_duplicate_mistakes_or_review_items(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $grammarPoint = GrammarPoint::query()->where('language', 'en')->where('code', 'present_simple')->firstOrFail();

        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'He go.'],
            ],
        ]);

        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->andReturn(new MistakeAnalysisResult([
            new AnalyzedMistake(
                grammarPoint: $grammarPoint,
                userUtterance: 'He go',
                correction: 'He goes',
                explanation: 'Subject-verb agreement',
            ),
        ]));

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts);

        $this->assertDatabaseCount('mistakes', 1);
        $this->assertDatabaseCount('review_items', 1);

        // Second run: analyzer must NOT be called again, count remains 1
        $job->handle($analyzer, $broadcasts);

        $this->assertDatabaseCount('mistakes', 1);
        $this->assertDatabaseCount('review_items', 1);
    }

    public function test_a_rerun_for_a_session_with_zero_mistakes_does_not_call_analyzer_again(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);

        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'Everything I said was grammatically correct.'],
            ],
        ]);

        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->andReturn(new MistakeAnalysisResult([]));

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts);

        $this->assertDatabaseCount('mistakes', 0);

        // Second run: must skip because session was already marked analysed
        $job->handle($analyzer, $broadcasts);

        $this->assertDatabaseCount('mistakes', 0);
    }

    public function test_it_does_nothing_when_session_is_not_terminal(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Active,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'He go.'],
            ],
        ]);

        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldNotReceive('analyze');

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts);

        $this->assertDatabaseCount('mistakes', 0);
    }

    public function test_it_does_nothing_when_session_has_no_learner_utterances(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Abandoned,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => null],
                ['turn_id' => 't2', 'transcript' => '   '],
            ],
        ]);

        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldNotReceive('analyze');

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts);

        $this->assertDatabaseCount('mistakes', 0);
        $broadcasts->shouldHaveReceived('broadcast')
            ->once()
            ->with(
                Mockery::type(SessionFeedbackReady::class),
                'sessionFeedbackReady',
                Mockery::on(static fn ($root): bool => $root instanceof VoiceSession && $root->id === $session->id),
            );
    }

    public function test_it_analyzes_late_turn_when_it_arrives_during_empty_utterance_check(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $presentSimple = GrammarPoint::query()->where('language', 'en')->where('code', 'present_simple')->firstOrFail();

        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [],
        ]);

        $updated = false;
        Event::listen(TransactionBeginning::class, static function () use ($session, &$updated): void {
            if (! $updated) {
                $updated = true;
                VoiceSession::query()->whereKey($session->id)->update([
                    'transcript' => [
                        ['turn_id' => 'late', 'transcript' => 'He go home.'],
                    ],
                ]);
            }
        });

        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldReceive('analyze')
            ->once()
            ->with('He go home.', Mockery::any())
            ->andReturn(new MistakeAnalysisResult([
                new AnalyzedMistake(
                    grammarPoint: $presentSimple,
                    userUtterance: 'He go home',
                    correction: 'He goes home',
                    explanation: 'Agreement rule',
                ),
            ]));

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts);

        $this->assertDatabaseCount('mistakes', 1);
        $this->assertDatabaseHas('mistakes', [
            'session_id' => $session->id,
            'user_utterance' => 'He go home',
        ]);
    }

    public function test_failed_reports_and_broadcasts_session_feedback_ready(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Completed,
        ]);

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->failed(new \RuntimeException('Analysis timed out'), $broadcasts);

        $broadcasts->shouldHaveReceived('broadcast')
            ->once()
            ->with(
                Mockery::type(SessionFeedbackReady::class),
                'sessionFeedbackReady',
                Mockery::on(static fn ($root): bool => $root instanceof VoiceSession && $root->id === $session->id),
            );
    }

    public function test_an_invalid_model_response_fails_the_job_loudly(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'Some utterance.'],
            ],
        ]);

        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldReceive('analyze')
            ->once()
            ->andThrow(new MistakeAnalysisFailed('Invalid model response.'));

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $this->expectException(MistakeAnalysisFailed::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts);
    }

    public function test_a_scenario_session_without_a_lesson_card_is_still_analysed(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $presentSimple = GrammarPoint::query()->where('language', 'en')->where('code', 'present_simple')->firstOrFail();

        // A scenario session: `lesson_card_id` is null and only the code names
        // what was practised. Analysis reads the transcript and the learner, not
        // the card, so it must run exactly as it does for a lesson.
        $session = VoiceSession::factory()->for($user)->scenario('airport_checkin')->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'He go to the airport yesterday.'],
            ],
        ]);

        $this->assertNull($session->lesson_card_id);
        $this->assertSame('airport_checkin', $session->scenario_code);

        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldReceive('analyze')
            ->once()
            ->with('He go to the airport yesterday.', Mockery::on(static fn ($u): bool => $u->is($user)))
            ->andReturn(new MistakeAnalysisResult([
                new AnalyzedMistake(
                    grammarPoint: $presentSimple,
                    userUtterance: 'He go to the airport',
                    correction: 'He went to the airport',
                    explanation: 'Past simple is required for a finished past event.',
                ),
            ]));

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        // The session is a scenario, so the job also runs the communication
        // review. It is mocked here to keep this test about grammar analysis.
        $reviewer = Mockery::mock(GoalReviewer::class);
        $reviewer->shouldReceive('review')
            ->once()
            ->andReturn(new GoalReviewResult(true, ['Could I have a window seat?']));

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts, $reviewer);

        $this->assertDatabaseCount('mistakes', 1);
        $this->assertDatabaseHas('mistakes', [
            'session_id' => $session->id,
            'user_id' => $user->id,
            'grammar_point_id' => $presentSimple->id,
            'user_utterance' => 'He go to the airport',
            'correction' => 'He went to the airport',
        ]);
        $this->assertDatabaseCount('review_items', 1);
        $this->assertTrue($session->fresh()->analyzed);
        $this->assertSame(
            ['goal_achieved' => true, 'missed_expressions' => ['Could I have a window seat?']],
            $session->fresh()->goal_review,
        );
    }

    public function test_a_scenario_session_stores_the_goal_review_and_broadcasts_it_once(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $grammarPoint = GrammarPoint::query()->where('language', 'en')->where('code', 'present_simple')->firstOrFail();

        $session = VoiceSession::factory()->for($user)->scenario('airport_checkin')->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'Good evening. Here is my passport.'],
                ['turn_id' => 't2', 'transcript' => 'I have a connecting flight to Seoul.'],
            ],
        ]);

        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->andReturn(new MistakeAnalysisResult([
            new AnalyzedMistake(
                grammarPoint: $grammarPoint,
                userUtterance: 'I have a connecting flight',
                correction: 'I have a connecting flight',
                explanation: 'No mistake, kept to prove grammar analysis still ran.',
            ),
        ]));

        $reviewer = Mockery::mock(GoalReviewer::class);
        $reviewer->shouldReceive('review')
            ->once()
            ->with(Mockery::on(static fn ($reviewed): bool => $reviewed instanceof VoiceSession && $reviewed->id === $session->id))
            ->andReturn(new GoalReviewResult(true, ['Will my bag go all the way through?']));

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts, $reviewer);

        $this->assertSame(
            ['goal_achieved' => true, 'missed_expressions' => ['Will my bag go all the way through?']],
            $session->fresh()->goal_review,
        );

        $broadcasts->shouldHaveReceived('broadcast')
            ->once()
            ->with(
                Mockery::type(SessionFeedbackReady::class),
                'sessionFeedbackReady',
                Mockery::on(static fn ($root): bool => $root instanceof VoiceSession
                    && $root->id === $session->id
                    && $root->goal_review === [
                        'goal_achieved' => true,
                        'missed_expressions' => ['Will my bag go all the way through?'],
                    ]),
            );
    }

    public function test_a_lesson_session_never_runs_the_goal_review(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'Everything I said was grammatically correct.'],
            ],
        ]);

        $this->assertNull($session->scenario_code);

        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->andReturn(new MistakeAnalysisResult([]));

        $reviewer = Mockery::mock(GoalReviewer::class);
        $reviewer->shouldNotReceive('review');

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts, $reviewer);

        $this->assertNull($session->fresh()->goal_review);
        $this->assertTrue($session->fresh()->analyzed);
    }

    public function test_a_failing_goal_review_keeps_the_mistakes_the_marker_and_the_broadcast(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $grammarPoint = GrammarPoint::query()->where('language', 'en')->where('code', 'present_simple')->firstOrFail();

        $session = VoiceSession::factory()->for($user)->scenario('airport_checkin')->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'He go to the airport.'],
            ],
        ]);

        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->andReturn(new MistakeAnalysisResult([
            new AnalyzedMistake(
                grammarPoint: $grammarPoint,
                userUtterance: 'He go to the airport',
                correction: 'He goes to the airport',
                explanation: 'Subject-verb agreement.',
            ),
        ]));

        $reviewer = Mockery::mock(GoalReviewer::class);
        $reviewer->shouldReceive('review')
            ->once()
            ->andThrow(new \RuntimeException('The goal review model timed out.'));

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts, $reviewer);

        $this->assertDatabaseCount('mistakes', 1);
        $this->assertTrue($session->fresh()->analyzed);
        $this->assertNull($session->fresh()->goal_review);

        $broadcasts->shouldHaveReceived('broadcast')
            ->once()
            ->with(
                Mockery::type(SessionFeedbackReady::class),
                'sessionFeedbackReady',
                Mockery::on(static fn ($root): bool => $root instanceof VoiceSession && $root->id === $session->id),
            );
    }

    public function test_a_rerun_does_not_run_the_goal_review_again(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $grammarPoint = GrammarPoint::query()->where('language', 'en')->where('code', 'present_simple')->firstOrFail();

        $session = VoiceSession::factory()->for($user)->scenario('airport_checkin')->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'He go to the airport.'],
            ],
        ]);

        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->andReturn(new MistakeAnalysisResult([
            new AnalyzedMistake(
                grammarPoint: $grammarPoint,
                userUtterance: 'He go to the airport',
                correction: 'He goes to the airport',
                explanation: 'Subject-verb agreement.',
            ),
        ]));

        $reviewer = Mockery::mock(GoalReviewer::class);
        $reviewer->shouldReceive('review')->once()->andReturn(new GoalReviewResult(true, []));

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts, $reviewer);

        $this->assertDatabaseCount('mistakes', 1);
        $this->assertNotNull($session->fresh()->goal_review);

        // Second run: neither the analyzer nor the reviewer may run again, and
        // no second broadcast is sent.
        $job->handle($analyzer, $broadcasts, $reviewer);

        $this->assertDatabaseCount('mistakes', 1);
        $this->assertDatabaseCount('review_items', 1);
        $this->assertSame(
            ['goal_achieved' => true, 'missed_expressions' => []],
            $session->fresh()->goal_review,
        );

        $broadcasts->shouldHaveReceived('broadcast')->once();
    }

    public function test_a_scenario_session_without_learner_utterances_skips_the_goal_review(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $session = VoiceSession::factory()->for($user)->scenario('airport_checkin')->create([
            'status' => VoiceSessionStatus::Abandoned,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => '   '],
            ],
        ]);

        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldNotReceive('analyze');

        $reviewer = Mockery::mock(GoalReviewer::class);
        $reviewer->shouldNotReceive('review');

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts, $reviewer);

        $this->assertNull($session->fresh()->goal_review);
        $this->assertTrue($session->fresh()->analyzed);
        $broadcasts->shouldHaveReceived('broadcast')->once();
    }
}
