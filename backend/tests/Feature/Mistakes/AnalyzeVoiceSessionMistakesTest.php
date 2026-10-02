<?php

namespace Tests\Feature\Mistakes;

use App\Enums\VoiceSessionStatus;
use App\GraphQL\Subscriptions\SessionFeedbackReady;
use App\Mistakes\AnalyzedMistake;
use App\Mistakes\AnalyzeVoiceSessionMistakes;
use App\Mistakes\MistakeAnalysisFailed;
use App\Mistakes\MistakeAnalysisResult;
use App\Mistakes\MistakeAnalyzer;
use App\Models\GrammarPoint;
use App\Models\ReviewItem;
use App\Models\User;
use App\Models\VoiceSession;
use Database\Seeders\GrammarPointSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
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
}
