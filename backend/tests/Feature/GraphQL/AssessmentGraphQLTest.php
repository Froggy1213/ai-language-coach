<?php

namespace Tests\Feature\GraphQL;

use App\Assessments\AnalyzeAssessment;
use App\Assessments\AssessmentAudioStorage;
use App\Assessments\AudioUpload;
use App\Assessments\InvalidAssessmentAudio;
use App\Enums\AssessmentStatus;
use App\Enums\CefrLevel;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class AssessmentGraphQLTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const PRESIGN = /** @lang GraphQL */ '
        mutation ($contentType: String!) {
            createAssessmentUploadUrl(contentType: $contentType) {
                uploadUrl
                fileUrl
                fields { name value }
            }
        }
    ';

    private const SUBMIT = /** @lang GraphQL */ '
        mutation ($audioUrl: String!) {
            submitAssessment(audioUrl: $audioUrl) {
                id
                status
                cefrLevel
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.s3.key' => 'test-key',
            'filesystems.disks.s3.secret' => 'test-secret',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'coach-audio',
        ]);
    }

    public function test_guests_cannot_presign_an_upload(): void
    {
        $this->graphQL(self::PRESIGN, ['contentType' => 'audio/webm'])
            ->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_guests_cannot_submit_an_assessment(): void
    {
        $this->graphQL(self::SUBMIT, ['audioUrl' => 'https://coach-audio.s3.amazonaws.com/assessments/1/a.webm'])
            ->assertGraphQLErrorMessage('Unauthenticated.');

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_it_presigns_a_limited_upload_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->graphQL(self::PRESIGN, ['contentType' => 'audio/webm;codecs=opus'])
            ->assertGraphQLErrorFree();

        /** @var array<string, string> $fields */
        $fields = collect($response->json('data.createAssessmentUploadUrl.fields'))
            ->pluck('value', 'name')
            ->all();

        $this->assertSame('audio/webm', $fields['Content-Type']);
        $this->assertStringStartsWith("assessments/{$user->id}/", $fields['key']);
        $this->assertStringEndsWith('/'.$fields['key'], (string) $response->json('data.createAssessmentUploadUrl.fileUrl'));

        /** @var array{conditions: list<array<int, mixed>>} $policy */
        $policy = json_decode(base64_decode($fields['Policy'], true) ?: '', true, flags: JSON_THROW_ON_ERROR);

        $this->assertContains(['content-length-range', 1, 15 * 1024 * 1024], $policy['conditions']);
        $this->assertContains(['eq', '$Content-Type', 'audio/webm'], $policy['conditions']);
    }

    public function test_it_refuses_a_content_type_it_cannot_transcribe(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->graphQL(self::PRESIGN, ['contentType' => 'application/pdf'])
            ->assertGraphQLValidationKeys(['contentType']);
    }

    public function test_it_queues_an_uploaded_recording_for_analysis(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fileUrl = $this->fakeUpload($user, sizeBytes: 2048);

        $this->graphQL(self::SUBMIT, ['audioUrl' => $fileUrl])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.submitAssessment.status', 'processing')
            ->assertJsonPath('data.submitAssessment.cefrLevel', null);

        $assessment = Assessment::query()->sole();

        $this->assertTrue($assessment->user->is($user));
        $this->assertSame($fileUrl, $assessment->audio_url);
        $this->assertSame(AssessmentStatus::Processing, $assessment->status);
        $this->assertSame(2048, $assessment->raw_data['upload']['size_bytes']);

        Queue::assertPushed(
            AnalyzeAssessment::class,
            static fn (AnalyzeAssessment $job): bool => $job->assessment->is($assessment),
        );
    }

    public function test_submitting_duplicate_audio_url_is_idempotent(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fileUrl = "https://coach-audio.s3.amazonaws.com/assessments/{$user->id}/recording.webm";
        $storage = Mockery::mock(AssessmentAudioStorage::class);
        $storage->shouldReceive('inspect')
            ->twice()
            ->with(Mockery::on(static fn (User $caller): bool => $caller->is($user)), $fileUrl)
            ->andReturn(new AudioUpload(
                key: "assessments/{$user->id}/recording.webm",
                fileUrl: $fileUrl,
                sizeBytes: 2048,
                contentType: 'audio/webm',
            ));
        $this->app->instance(AssessmentAudioStorage::class, $storage);

        $firstResponse = $this->graphQL(self::SUBMIT, ['audioUrl' => $fileUrl]);
        $firstResponse->assertGraphQLErrorFree();
        $firstId = $firstResponse->json('data.submitAssessment.id');

        $secondResponse = $this->graphQL(self::SUBMIT, ['audioUrl' => $fileUrl]);
        $secondResponse->assertGraphQLErrorFree();
        $secondId = $secondResponse->json('data.submitAssessment.id');

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, Assessment::query()->where('user_id', $user->id)->count());
        Queue::assertPushed(AnalyzeAssessment::class, 1);
    }

    public function test_it_refuses_an_upload_that_does_not_belong_to_the_learner(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        $storage = Mockery::mock(AssessmentAudioStorage::class);
        $storage->shouldReceive('inspect')
            ->andThrow(new InvalidAssessmentAudio('That upload does not belong to this learner.'));
        $this->app->instance(AssessmentAudioStorage::class, $storage);

        $this->graphQL(self::SUBMIT, ['audioUrl' => 'https://coach-audio.s3.amazonaws.com/assessments/999/other.webm'])
            ->assertGraphQLValidationError('audioUrl', 'That upload does not belong to this learner.');

        $this->assertDatabaseCount('assessments', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_validates_the_audio_url(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->graphQL(self::SUBMIT, ['audioUrl' => 'not-a-url'])
            ->assertGraphQLValidationKeys(['audioUrl']);

        $this->assertDatabaseCount('assessments', 0);
    }

    /**
     * The level screen reads the clarity block back through `me` after a reload:
     * the subscription that closed the recording step is gone, so without this the
     * section would disappear on the next visit.
     */
    public function test_it_returns_the_latest_finished_assessments_clarity_evidence(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Assessment::factory()->for($user)->create(['status' => AssessmentStatus::Failed]);

        $old = Assessment::factory()->for($user)->create([
            'status' => AssessmentStatus::Done,
            'cefr_level' => CefrLevel::A2,
        ]);

        Assessment::factory()->for($user)->create([
            'status' => AssessmentStatus::Done,
            'cefr_level' => CefrLevel::B1,
        ])->forceFill(['created_at' => now()->addMinute()])->save();

        $response = $this->graphQL(/** @lang GraphQL */ '
            query {
                me {
                    latestAssessment {
                        id
                        status
                        cefrLevel
                        pronunciation {
                            band
                            clarityPercent
                            uncertainThreshold
                            isThinSample
                            advice
                            uncertainWords { word confidence }
                        }
                    }
                }
            }
        ')->assertGraphQLErrorFree();

        // The newest `done` row, not the newest row: an assessment still running has
        // no result, and a failed one is a retry rather than a result to show.
        $this->assertNotSame((string) $old->id, (string) $response->json('data.me.latestAssessment.id'));
        $this->assertSame('B1', $response->json('data.me.latestAssessment.cefrLevel'));
        $this->assertNull($response->json('data.me.latestAssessment.pronunciation'));
    }

    public function test_it_returns_an_assessments_stored_clarity_block(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $assessment = Assessment::factory()->for($user)->create([
            'status' => AssessmentStatus::Done,
            'cefr_level' => CefrLevel::B1,
            'raw_data' => [
                'transcript' => 'I thought it was thorough.',
                // Exactly the shape `PronunciationAssessment::toArray()` writes.
                'pronunciation' => [
                    'band' => 'mostly_clear',
                    'clarity_percent' => 83,
                    'uncertain_threshold' => 0.65,
                    'uncertain_words' => [
                        ['word' => 'thought', 'confidence' => 0.3],
                        ['word' => 'thorough', 'confidence' => 0.4],
                    ],
                    'is_thin_sample' => false,
                    'advice' => '「thought」という単語をもう一度ゆっくり言ってみましょう。',
                ],
            ],
        ]);

        $response = $this->graphQL(/** @lang GraphQL */ '
            query {
                me {
                    latestAssessment {
                        pronunciation {
                            band
                            clarityPercent
                            uncertainThreshold
                            isThinSample
                            advice
                            uncertainWords { word confidence }
                        }
                    }
                }
            }
        ')->assertGraphQLErrorFree();

        $this->assertSame('mostly_clear', $response->json('data.me.latestAssessment.pronunciation.band'));
        $this->assertSame(83, $response->json('data.me.latestAssessment.pronunciation.clarityPercent'));
        $this->assertSame(0.65, $response->json('data.me.latestAssessment.pronunciation.uncertainThreshold'));
        $this->assertFalse($response->json('data.me.latestAssessment.pronunciation.isThinSample'));
        $this->assertSame(
            '「thought」という単語をもう一度ゆっくり言ってみましょう。',
            $response->json('data.me.latestAssessment.pronunciation.advice'),
        );
        $this->assertSame([
            ['word' => 'thought', 'confidence' => 0.3],
            ['word' => 'thorough', 'confidence' => 0.4],
        ], $response->json('data.me.latestAssessment.pronunciation.uncertainWords'));

        // The raw transcript is deliberately not exposed: the learner reads the
        // clarity evidence, the stored analysis stays server-side.
        $this->assertNotNull($assessment->refresh()->raw_data['transcript']);
    }

    public function test_a_learner_with_no_finished_assessment_has_no_latest_assessment(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Assessment::factory()->for($user)->create(['status' => AssessmentStatus::Processing]);

        $this->graphQL(/** @lang GraphQL */ '
            query {
                me {
                    latestAssessment { id }
                }
            }
        ')
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.me.latestAssessment', null);
    }

    private function fakeUpload(User $user, int $sizeBytes, string $contentType = 'audio/webm'): string
    {
        $fileUrl = "https://coach-audio.s3.amazonaws.com/assessments/{$user->id}/recording.webm";

        $storage = Mockery::mock(AssessmentAudioStorage::class);
        $storage->shouldReceive('inspect')
            ->once()
            ->with(Mockery::on(static fn (User $caller): bool => $caller->is($user)), $fileUrl)
            ->andReturn(new AudioUpload(
                key: "assessments/{$user->id}/recording.webm",
                fileUrl: $fileUrl,
                sizeBytes: $sizeBytes,
                contentType: $contentType,
            ));
        $this->app->instance(AssessmentAudioStorage::class, $storage);

        return $fileUrl;
    }
}
