<?php

namespace Tests\Feature\Voice;

use App\Enums\VoiceSessionStatus;
use App\Mistakes\AnalyzeVoiceSessionMistakes;
use App\Mistakes\MistakeAnalysisResult;
use App\Mistakes\MistakeAnalyzer;
use App\Models\User;
use App\Models\VoiceSession;
use Database\Seeders\GrammarPointSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Nuwave\Lighthouse\Subscriptions\Contracts\BroadcastsSubscriptions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class VoiceSessionAnalyzedTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const QUERY = /** @lang GraphQL */ '
        query ($id: ID!) {
            voiceSession(id: $id) {
                id
                status
                analyzed
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrammarPointSeeder::class);
    }

    public function test_voice_session_analyzed_is_false_before_analysis_and_true_after_analysis_with_zero_mistakes(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [
                ['turn_id' => 't1', 'transcript' => 'This sentence is completely correct.'],
            ],
        ]);

        Sanctum::actingAs($user);

        // Before AnalyzeVoiceSessionMistakes runs: analyzed must be false
        $responseBefore = $this->graphQL(self::QUERY, ['id' => (string) $session->id])
            ->assertGraphQLErrorFree();

        $this->assertFalse($responseBefore->json('data.voiceSession.analyzed'));

        // Run AnalyzeVoiceSessionMistakes with 0 mistakes
        $analyzer = Mockery::mock(MistakeAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()->andReturn(new MistakeAnalysisResult([]));

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $job = new AnalyzeVoiceSessionMistakes($session);
        $job->handle($analyzer, $broadcasts);

        // After AnalyzeVoiceSessionMistakes finishes: analyzed must be true
        $responseAfter = $this->graphQL(self::QUERY, ['id' => (string) $session->id])
            ->assertGraphQLErrorFree();

        $this->assertTrue($responseAfter->json('data.voiceSession.analyzed'));
    }

    public function test_voice_session_analyzed_is_true_for_failed_sessions(): void
    {
        $user = User::factory()->create(['target_language' => 'en']);
        $session = VoiceSession::factory()->for($user)->create([
            'status' => VoiceSessionStatus::Failed,
            'transcript' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->graphQL(self::QUERY, ['id' => (string) $session->id])
            ->assertGraphQLErrorFree();

        $this->assertTrue($response->json('data.voiceSession.analyzed'));
    }
}
