<?php

namespace Tests\Feature\Assessments;

use App\Assessments\AnalyzeAssessment;
use App\Assessments\AssessmentAnalysisFailed;
use App\Assessments\AssessmentAudioStorage;
use App\Assessments\AssessmentResult;
use App\Assessments\AudioUpload;
use App\Assessments\CefrAssessor;
use App\Assessments\DeepgramTranscriber;
use App\Assessments\Recording;
use App\Enums\AssessmentStatus;
use App\Enums\CefrLevel;
use App\Enums\RoadmapStatus;
use App\Grammar\RoadmapGenerator;
use App\GraphQL\Subscriptions\AssessmentReady;
use App\Models\Assessment;
use App\Models\User;
use Database\Seeders\GrammarPointSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Nuwave\Lighthouse\Subscriptions\Contracts\BroadcastsSubscriptions;
use RuntimeException;
use Tests\TestCase;

class AnalyzeAssessmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const AUDIO_URL = 'https://coach-audio.s3.amazonaws.com/assessments/1/recording.webm';

    public function test_it_transcribes_analyses_and_rebuilds_the_roadmap(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $this->fakeDeepgram('I have been learning English for six years.');

        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::A1]);
        $firstRoadmap = $this->app->make(RoadmapGenerator::class)->generate($user);
        $assessment = $this->assessmentFor($user);

        $audio = $this->audioStore();
        $audio->shouldReceive('delete')->once()->with($assessment->audio_url);

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $this->runJob($assessment, $this->assessorReturning(new AssessmentResult(
            level: CefrLevel::B1,
            summary: 'You are at B1.',
            strengths: ['Clear everyday vocabulary'],
            weaknesses: ['Present perfect'],
        )), $audio, $broadcasts);

        $assessment->refresh();

        $this->assertSame(AssessmentStatus::Done, $assessment->status);
        $this->assertSame(CefrLevel::B1, $assessment->cefr_level);
        $this->assertSame('I have been learning English for six years.', $assessment->raw_data['transcript']);
        $this->assertSame(42.5, $assessment->raw_data['duration_seconds']);
        $this->assertSame('B1', $assessment->raw_data['analysis']['cefr_level']);
        $this->assertSame(['Present perfect'], $assessment->raw_data['analysis']['weaknesses']);

        $this->assertSame(CefrLevel::B1, $user->refresh()->current_level);

        $this->assertSame(RoadmapStatus::Archived, $firstRoadmap->refresh()->status);
        $roadmap = $user->roadmap;
        $this->assertNotNull($roadmap);
        $this->assertSame('English · A1–B1', $roadmap->title);
        $this->assertGreaterThan(
            $firstRoadmap->lessonCards->count(),
            $roadmap->lessonCards->count(),
            'The roadmap must follow the level the analysis settled on.',
        );

        $broadcasts->shouldHaveReceived('broadcast')
            ->once()
            ->with(
                Mockery::type(AssessmentReady::class),
                'assessmentReady',
                Mockery::on(static fn ($root): bool => $root->is($assessment)),
            );
    }

    public function test_it_leaves_an_assessment_that_already_finished_alone(): void
    {
        Http::fake();

        $assessment = Assessment::factory()->create([
            'status' => AssessmentStatus::Done,
            'cefr_level' => CefrLevel::B1,
            'audio_url' => self::AUDIO_URL,
        ]);

        $audio = Mockery::mock(AssessmentAudioStorage::class);
        $audio->shouldNotReceive('inspect');
        $audio->shouldNotReceive('fetch');
        $audio->shouldNotReceive('delete');

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        $this->runJob($assessment, $this->assessorReturning($this->b1Result()), $audio, $broadcasts);

        Http::assertNothingSent();
        $broadcasts->shouldNotHaveReceived('broadcast');
        $this->assertSame(AssessmentStatus::Done, $assessment->refresh()->status);
    }

    public function test_it_keeps_the_recording_when_the_analysis_fails_so_a_retry_can_use_it(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $this->fakeDeepgram('Some transcript.');

        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::A1]);
        $assessment = $this->assessmentFor($user);

        $audio = $this->audioStore();
        $audio->shouldNotReceive('delete');

        $assessor = Mockery::mock(CefrAssessor::class);
        $assessor->shouldReceive('assess')->once()->andThrow(new AssessmentAnalysisFailed('The model returned nonsense.'));

        try {
            $this->runJob($assessment, $assessor, $audio, $this->spy(BroadcastsSubscriptions::class));
            $this->fail('The analysis was expected to fail.');
        } catch (AssessmentAnalysisFailed) {
            // The queue retries the job; the assertions below describe the state
            // that retry depends on.
        }

        $this->assertSame(AssessmentStatus::Processing, $assessment->refresh()->status);
        $this->assertSame(CefrLevel::A1, $user->refresh()->current_level);
        $this->assertNull($user->roadmap);
    }

    public function test_a_broadcast_that_cannot_be_delivered_does_not_throw_the_analysis_away(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $this->fakeDeepgram('I have been learning English for six years.');

        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::A1]);
        $assessment = $this->assessmentFor($user);

        $audio = $this->audioStore();
        $audio->shouldReceive('delete')->once()->with($assessment->audio_url);

        // What Lighthouse does when one subscriber in the topic cannot be
        // restored — a deleted user, for instance.
        $broadcasts = Mockery::mock(BroadcastsSubscriptions::class);
        $broadcasts->shouldReceive('broadcast')->andThrow(new RuntimeException('No query results for model [App\\Models\\User].'));

        $this->runJob($assessment, $this->assessorReturning($this->b1Result()), $audio, $broadcasts);

        $this->assertSame(AssessmentStatus::Done, $assessment->refresh()->status);
        $this->assertSame(CefrLevel::B1, $user->refresh()->current_level);
        $this->assertSame('English · A1–B1', $user->roadmap?->title);
    }

    public function test_it_marks_the_assessment_failed_once_the_attempts_run_out(): void
    {
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::A1]);
        $assessment = Assessment::factory()->for($user)->create([
            'status' => AssessmentStatus::Processing,
            'audio_url' => self::AUDIO_URL,
            'raw_data' => ['upload' => ['key' => 'assessments/1/recording.webm']],
        ]);

        (new AnalyzeAssessment($assessment))->failed(new RuntimeException('Deepgram timed out'));

        $assessment->refresh();

        $this->assertSame(AssessmentStatus::Failed, $assessment->status);
        $this->assertSame('Deepgram timed out', $assessment->raw_data['error']);
        $this->assertSame(['key' => 'assessments/1/recording.webm'], $assessment->raw_data['upload']);
        $this->assertSame(CefrLevel::A1, $user->refresh()->current_level);
    }

    public function test_a_late_failure_does_not_overwrite_a_finished_assessment(): void
    {
        $assessment = Assessment::factory()->create([
            'status' => AssessmentStatus::Done,
            'cefr_level' => CefrLevel::B1,
        ]);

        (new AnalyzeAssessment($assessment))->failed(new RuntimeException('late failure'));

        $this->assertSame(AssessmentStatus::Done, $assessment->refresh()->status);
        $this->assertSame(CefrLevel::B1, $assessment->cefr_level);
    }

    private function runJob(
        Assessment $assessment,
        CefrAssessor $assessor,
        AssessmentAudioStorage $audio,
        BroadcastsSubscriptions $broadcasts,
    ): void {
        (new AnalyzeAssessment($assessment))->handle(
            $this->app->make(DeepgramTranscriber::class),
            $assessor,
            $audio,
            $this->app->make(RoadmapGenerator::class),
            $broadcasts,
        );
    }

    /**
     * The bucket, stubbed: the job reads the object back and checks it again
     * before anything is transcribed.
     */
    private function audioStore(): MockInterface
    {
        $audio = Mockery::mock(AssessmentAudioStorage::class);
        $audio->shouldReceive('inspect')->andReturn(new AudioUpload(
            key: 'assessments/1/recording.webm',
            fileUrl: self::AUDIO_URL,
            sizeBytes: 2048,
            contentType: 'audio/webm',
        ));
        $audio->shouldReceive('fetch')->andReturn(new Recording(
            contents: 'fake-webm-bytes',
            contentType: 'audio/webm',
        ));

        return $audio;
    }

    private function assessorReturning(AssessmentResult $result): CefrAssessor
    {
        $assessor = Mockery::mock(CefrAssessor::class);
        $assessor->shouldReceive('assess')->andReturn($result);

        return $assessor;
    }

    private function b1Result(): AssessmentResult
    {
        return new AssessmentResult(
            level: CefrLevel::B1,
            summary: 'You are at B1.',
            strengths: ['Clear everyday vocabulary'],
            weaknesses: ['Present perfect'],
        );
    }

    private function assessmentFor(User $user): Assessment
    {
        return Assessment::factory()->for($user)->create([
            'status' => AssessmentStatus::Processing,
            'audio_url' => str_replace('/assessments/1/', "/assessments/{$user->id}/", self::AUDIO_URL),
        ]);
    }

    private function fakeDeepgram(string $transcript): void
    {
        config(['deepgram-laravel.api_key' => 'test-key']);

        Http::fake([
            'api.deepgram.com/*' => Http::response([
                'metadata' => ['duration' => 42.5],
                'results' => [
                    'channels' => [['alternatives' => [['transcript' => $transcript, 'confidence' => 0.98]]]],
                ],
            ]),
        ]);
    }
}
