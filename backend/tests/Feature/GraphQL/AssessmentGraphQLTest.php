<?php

namespace Tests\Feature\GraphQL;

use App\Assessments\AnalyzeAssessment;
use App\Assessments\AssessmentAudioStorage;
use App\Assessments\AudioUpload;
use App\Assessments\InvalidAssessmentAudio;
use App\Enums\AssessmentStatus;
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
