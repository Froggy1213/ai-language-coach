<?php

namespace Tests\Feature\Assessments;

use App\Assessments\AnalyzeAssessment;
use App\Assessments\AssessmentAnalysisFailed;
use App\Assessments\AssessmentAudioStorage;
use App\Assessments\AssessmentResult;
use App\Assessments\AudioUpload;
use App\Assessments\CefrAssessor;
use App\Assessments\DeepgramTranscriber;
use App\Assessments\PronunciationAnalyzer;
use App\Assessments\PronunciationAssessment;
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

    /** @var list<string> */
    private array $stagedRecordings = [];

    protected function tearDown(): void
    {
        foreach ($this->stagedRecordings as $path) {
            @unlink($path);
        }

        $this->stagedRecordings = [];

        parent::tearDown();
    }

    public function test_it_transcribes_analyses_and_rebuilds_the_roadmap(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $this->fakeDeepgram('The thought about it was thorough enough.', $this->wordsWithFourUncertain());

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
        $this->assertSame('The thought about it was thorough enough.', $assessment->raw_data['transcript']);
        $this->assertSame(42.5, $assessment->raw_data['duration_seconds']);
        $this->assertSame('B1', $assessment->raw_data['analysis']['cefr_level']);
        $this->assertSame(['Present perfect'], $assessment->raw_data['analysis']['weaknesses']);

        // The delivery evidence is stored with the level it produced, so a band
        // that looks wrong later can be explained from what the model was told.
        $this->assertSame(24, $assessment->raw_data['speech_features']['words_recognised']);
        $this->assertSame(0.167, $assessment->raw_data['speech_features']['low_confidence_word_share']);
        $this->assertSame(0.1, $assessment->raw_data['speech_features']['longest_pause_between_words_seconds']);
        // Round-tripped through a JSON column, so a whole figure comes back as an
        // integer rather than the float it was computed as.
        $this->assertEquals(150, $assessment->raw_data['speech_features']['words_per_minute']);

        // The clarity evidence is stored beside the transcript it was measured
        // from, so a score a learner questions later can be explained from the
        // words it came from.
        $this->assertSame(83, $assessment->raw_data['pronunciation']['clarity_percent']);
        $this->assertSame(0.65, $assessment->raw_data['pronunciation']['uncertain_threshold']);
        $this->assertSame(
            ['thought', 'thorough', 'thirty', 'three'],
            array_column($assessment->raw_data['pronunciation']['uncertain_words'], 'word'),
        );

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

    public function test_it_stores_the_pronunciation_evidence_the_broadcast_carries(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $this->fakeDeepgram('The thought about it was thorough enough.', $this->wordsWithFourUncertain());

        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::A1]);
        $assessment = $this->assessmentFor($user);

        $audio = $this->audioStore();
        $audio->shouldReceive('delete')->once()->with($assessment->audio_url);

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        // The stub stands in for the model, which adds the sentence and leaves the
        // measurement the job took untouched.
        $this->runJob($assessment, $this->assessorReturning($this->b1Result()->withPronunciation(
            new PronunciationAssessment(
                band: PronunciationAssessment::BAND_MOSTLY_CLEAR,
                clarityPercent: 83,
                uncertainWords: [
                    ['word' => 'thought', 'confidence' => 0.3],
                    ['word' => 'thorough', 'confidence' => 0.4],
                ],
                isThinSample: false,
                uncertainThreshold: 0.65,
                advice: '「thought」という単語をもう一度ゆっくり言ってみましょう。',
            ),
        )), $audio, $broadcasts);

        $stored = $assessment->refresh()->raw_data['pronunciation'];

        $this->assertSame('mostly_clear', $stored['band']);
        $this->assertSame(83, $stored['clarity_percent']);
        $this->assertSame([
            ['word' => 'thought', 'confidence' => 0.3],
            ['word' => 'thorough', 'confidence' => 0.4],
        ], $stored['uncertain_words']);
        $this->assertSame('「thought」という単語をもう一度ゆっくり言ってみましょう。', $stored['advice']);

        // The subscriber that wakes the level screen must receive the block, not
        // an instance loaded before the transaction wrote it: the screen reads the
        // result straight off the push.
        $broadcasts->shouldHaveReceived('broadcast')
            ->once()
            ->with(
                Mockery::type(AssessmentReady::class),
                'assessmentReady',
                Mockery::on(static fn ($root): bool => $root->raw_data['pronunciation']['clarity_percent'] === 83),
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

    public function test_it_exits_cleanly_when_the_user_was_deleted_while_queued(): void
    {
        $user = User::factory()->create();
        $assessment = $this->assessmentFor($user);

        // Simulate user relation resolving to null (e.g. if user was deleted between fresh() and ->user)
        User::retrieved(static function (User $retrieved) use ($user): void {
            if ($retrieved->id === $user->id) {
                $retrieved->exists = false;
            }
        });
        $user->delete();

        $audio = Mockery::mock(AssessmentAudioStorage::class);
        $audio->shouldNotReceive('inspect');
        $audio->shouldNotReceive('fetch');

        $assessor = Mockery::mock(CefrAssessor::class);
        $broadcasts = Mockery::mock(BroadcastsSubscriptions::class);

        $this->runJob($assessment, $assessor, $audio, $broadcasts);

        $this->assertNull($assessment->fresh());
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

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        (new AnalyzeAssessment($assessment))->failed(new RuntimeException('Deepgram timed out'), $broadcasts);

        $assessment->refresh();

        $this->assertSame(AssessmentStatus::Failed, $assessment->status);
        $this->assertSame('Deepgram timed out', $assessment->raw_data['error']);
        $this->assertSame(['key' => 'assessments/1/recording.webm'], $assessment->raw_data['upload']);
        $this->assertSame(CefrLevel::A1, $user->refresh()->current_level);

        $broadcasts->shouldHaveReceived('broadcast')
            ->once()
            ->with(
                Mockery::type(AssessmentReady::class),
                'assessmentReady',
                Mockery::on(static fn ($root): bool => $root->is($assessment) && $root->status === AssessmentStatus::Failed),
            );
    }

    public function test_a_late_failure_does_not_overwrite_a_finished_assessment(): void
    {
        $assessment = Assessment::factory()->create([
            'status' => AssessmentStatus::Done,
            'cefr_level' => CefrLevel::B1,
        ]);

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        (new AnalyzeAssessment($assessment))->failed(new RuntimeException('late failure'), $broadcasts);

        $this->assertSame(AssessmentStatus::Done, $assessment->refresh()->status);
        $this->assertSame(CefrLevel::B1, $assessment->cefr_level);
        $broadcasts->shouldNotHaveReceived('broadcast');
    }

    public function test_a_redelivery_does_not_broadcast_again_for_an_already_failed_assessment(): void
    {
        $assessment = Assessment::factory()->create([
            'status' => AssessmentStatus::Failed,
            'raw_data' => ['error' => 'First failure'],
        ]);

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        (new AnalyzeAssessment($assessment))->failed(new RuntimeException('Duplicate failure'), $broadcasts);

        $this->assertSame(AssessmentStatus::Failed, $assessment->refresh()->status);
        $this->assertSame('First failure', $assessment->refresh()->raw_data['error']);
        $broadcasts->shouldNotHaveReceived('broadcast');
    }

    public function test_a_broadcast_that_cannot_be_delivered_on_failure_does_not_mask_the_failure(): void
    {
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::A1]);
        $assessment = Assessment::factory()->for($user)->create([
            'status' => AssessmentStatus::Processing,
            'audio_url' => self::AUDIO_URL,
        ]);

        $broadcasts = Mockery::mock(BroadcastsSubscriptions::class);
        $broadcasts->shouldReceive('broadcast')->andThrow(new RuntimeException('No query results for model [App\\Models\\User].'));

        (new AnalyzeAssessment($assessment))->failed(new RuntimeException('Deepgram timed out'), $broadcasts);

        $this->assertSame(AssessmentStatus::Failed, $assessment->refresh()->status);
        $this->assertSame('Deepgram timed out', $assessment->raw_data['error']);
    }

    public function test_when_roadmap_regenerate_throws_assessment_remains_processing(): void
    {
        $this->fakeDeepgram('Some transcript.');
        // 'fr' has no grammar catalogue file in resources/grammar, so RoadmapGenerator::regenerate throws RuntimeException.
        $user = User::factory()->create(['target_language' => 'fr', 'current_level' => CefrLevel::A1]);
        $assessment = $this->assessmentFor($user);

        $audio = $this->audioStore();
        $audio->shouldNotReceive('delete');

        $broadcasts = $this->spy(BroadcastsSubscriptions::class);

        try {
            $this->runJob($assessment, $this->assessorReturning($this->b1Result()), $audio, $broadcasts);
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No grammar points are catalogued for `fr`', $exception->getMessage());
        }

        $this->assertSame(AssessmentStatus::Processing, $assessment->fresh()->status);
        $this->assertSame(CefrLevel::A1, $user->fresh()->current_level);
        $broadcasts->shouldNotHaveReceived('broadcast');
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
            $this->app->make(PronunciationAnalyzer::class),
            $audio,
            $this->app->make(RoadmapGenerator::class),
            $broadcasts,
        );
    }

    /**
     * The bucket, stubbed: the job streams the object back into a staged file,
     * checks it again, and transcribes from that file.
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
        $audio->shouldReceive('fetch')->andReturnUsing(function (): Recording {
            $path = tempnam(sys_get_temp_dir(), 'assessment-recording-');
            file_put_contents($path, 'fake-webm-bytes');

            // The job is supposed to discard this; registering it here means a
            // regression shows up as a stray file rather than as a silent leak.
            $this->stagedRecordings[] = $path;

            return new Recording(path: $path, contentType: 'audio/webm');
        });

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

    /**
     * @param  list<array<string, mixed>>|null  $words  Word timings and confidences; null for the default pair.
     */
    private function fakeDeepgram(string $transcript, ?array $words = null): void
    {
        config(['deepgram-laravel.api_key' => 'test-key']);

        Http::fake([
            'api.deepgram.com/*' => Http::response([
                'metadata' => ['duration' => 42.5],
                'results' => [
                    'channels' => [['alternatives' => [[
                        'transcript' => $transcript,
                        'confidence' => 0.98,
                        'words' => $words ?? [
                            ['word' => 'i', 'punctuated_word' => 'I', 'start' => 0.0, 'end' => 0.3, 'confidence' => 0.99],
                            ['word' => 'um', 'punctuated_word' => 'um', 'start' => 1.6, 'end' => 1.8, 'confidence' => 0.71],
                        ],
                    ]]]],
                ],
            ]),
        ]);
    }

    /**
     * Twenty-four scored words, four of them unclear at the confidence the
     * analyzer's list is built from: 83% of them came through clearly.
     *
     * @return list<array<string, mixed>>
     */
    private function wordsWithFourUncertain(): array
    {
        $unclear = ['thought' => 0.3, 'thorough' => 0.4, 'thirty' => 0.5, 'three' => 0.6];
        $words = [];
        $start = 0.0;

        foreach ($unclear as $word => $confidence) {
            $words[] = $this->spokenWord($word, $start, $confidence);
            $start += 0.5;
        }

        for ($index = 1; $index <= 20; $index++) {
            $words[] = $this->spokenWord('word'.$index, $start, 0.97);
            $start += 0.5;
        }

        return $words;
    }

    /**
     * @return array<string, mixed>
     */
    private function spokenWord(string $word, float $start, float $confidence): array
    {
        return [
            'word' => $word,
            'punctuated_word' => $word,
            'start' => $start,
            'end' => $start + 0.4,
            'confidence' => $confidence,
        ];
    }
}
