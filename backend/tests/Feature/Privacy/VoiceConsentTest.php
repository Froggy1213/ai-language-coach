<?php

namespace Tests\Feature\Privacy;

use App\Assessments\AssessmentAudioStorage;
use App\Assessments\AudioUpload;
use App\Enums\LessonCardStatus;
use App\Models\LessonCard;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class VoiceConsentTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const SUBMIT_ASSESSMENT = /** @lang GraphQL */ '
        mutation ($audioUrl: String!) {
            submitAssessment(audioUrl: $audioUrl) {
                id
                status
            }
        }
    ';

    private const REQUEST_VOICE_TOKEN = /** @lang GraphQL */ '
        mutation ($lessonCardId: ID!) {
            requestVoiceToken(lessonCardId: $lessonCardId) {
                id
                status
            }
        }
    ';

    private const RECORD_CONSENT = /** @lang GraphQL */ '
        mutation {
            recordVoiceConsent {
                id
                voiceConsentAt
            }
        }
    ';

    private const ME_QUERY = /** @lang GraphQL */ '
        query {
            me {
                id
                voiceConsentAt
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
            'voice.livekit.url' => 'wss://livekit.test',
            'voice.livekit.api_key' => 'test-api-key',
            'voice.livekit.api_secret' => 'test-api-secret-that-is-long-enough',
            'voice.agent.join_timeout_seconds' => 5.0,
        ]);

        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_submitting_an_assessment_requires_explicit_consent(): void
    {
        $user = User::factory()->withoutVoiceConsent()->create();
        Sanctum::actingAs($user);

        $fileUrl = "https://coach-audio.s3.amazonaws.com/assessments/{$user->id}/recording.webm";

        $response = $this->graphQL(self::SUBMIT_ASSESSMENT, ['audioUrl' => $fileUrl]);

        $this->assertSame('VOICE_CONSENT_REQUIRED', $response->json('errors.0.extensions.code'));
        $this->assertNull($user->refresh()->voice_consent_at);
    }

    public function test_a_consented_learner_can_submit_an_assessment(): void
    {
        $user = User::factory()->withoutVoiceConsent()->create();
        Sanctum::actingAs($user);

        $this->graphQL(self::RECORD_CONSENT)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.recordVoiceConsent.id', (string) $user->id);

        $user->refresh();
        $this->assertNotNull($user->voice_consent_at);
        $this->assertTrue($user->hasGivenVoiceConsent());

        $fileUrl = "https://coach-audio.s3.amazonaws.com/assessments/{$user->id}/recording.webm";
        $this->app->instance(AssessmentAudioStorage::class, $this->fakeStorage($user, $fileUrl));

        $this->graphQL(self::SUBMIT_ASSESSMENT, ['audioUrl' => $fileUrl])
            ->assertGraphQLErrorFree();
    }

    public function test_starting_a_voice_session_requires_explicit_consent(): void
    {
        $user = User::factory()->withoutVoiceConsent()->create();
        $roadmap = Roadmap::factory()->for($user)->create();
        $card = LessonCard::factory()->for($roadmap)->create(['status' => LessonCardStatus::Ready]);

        Sanctum::actingAs($user);

        $response = $this->graphQL(self::REQUEST_VOICE_TOKEN, ['lessonCardId' => (string) $card->id]);

        $this->assertSame('VOICE_CONSENT_REQUIRED', $response->json('errors.0.extensions.code'));
        $this->assertNull($user->refresh()->voice_consent_at);
        Http::assertNothingSent();

        $this->graphQL(self::RECORD_CONSENT)->assertGraphQLErrorFree();

        Http::fake([
            'https://livekit.test/twirp/livekit.RoomService/CreateRoom' => Http::response(['sid' => 'RM_1']),
            'https://livekit.test/twirp/livekit.AgentDispatchService/CreateDispatch' => Http::response(['id' => 'DISP_1']),
            'https://livekit.test/twirp/livekit.RoomService/ListParticipants' => Http::response([
                'participants' => [
                    ['identity' => 'agent-1', 'kind' => 'AGENT'],
                ],
            ]),
        ]);

        $this->graphQL(self::REQUEST_VOICE_TOKEN, ['lessonCardId' => (string) $card->id])
            ->assertGraphQLErrorFree();

        $user->refresh();
        $this->assertNotNull($user->voice_consent_at);
    }

    public function test_recording_consent_is_idempotent_and_keeps_the_first_timestamp(): void
    {
        $originalConsent = Date::now()->subMonths(2);
        $user = User::factory()->create(['voice_consent_at' => $originalConsent]);
        Sanctum::actingAs($user);

        $this->graphQL(self::RECORD_CONSENT)->assertGraphQLErrorFree();

        $user->refresh();
        $this->assertSame($originalConsent->toDateTimeString(), $user->voice_consent_at->toDateTimeString());
    }

    public function test_guests_cannot_record_voice_consent(): void
    {
        $this->graphQL(self::RECORD_CONSENT)
            ->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_the_original_consent_timestamp_survives_a_later_submission(): void
    {
        $originalConsent = Date::now()->subMonths(2);
        $user = User::factory()->create(['voice_consent_at' => $originalConsent]);
        Sanctum::actingAs($user);

        $fileUrl = "https://coach-audio.s3.amazonaws.com/assessments/{$user->id}/recording.webm";
        $this->app->instance(AssessmentAudioStorage::class, $this->fakeStorage($user, $fileUrl));

        $this->graphQL(self::SUBMIT_ASSESSMENT, ['audioUrl' => $fileUrl])
            ->assertGraphQLErrorFree();

        $user->refresh();
        $this->assertSame($originalConsent->toDateTimeString(), $user->voice_consent_at->toDateTimeString());
    }

    public function test_a_client_cannot_forge_a_voice_consent_timestamp(): void
    {
        // 1. GraphQL schema rejects voiceConsentAt on register mutation input
        $query = /** @lang GraphQL */ '
            mutation ($name: String!, $email: String!, $password: String!, $targetLanguage: String!, $voiceConsentAt: String) {
                register(name: $name, email: $email, password: $password, targetLanguage: $targetLanguage, voiceConsentAt: $voiceConsentAt) {
                    id
                }
            }
        ';

        $response = $this->graphQL($query, [
            'name' => 'Test',
            'email' => 'newuser@example.com',
            'password' => 'secret1234',
            'targetLanguage' => 'en',
            'voiceConsentAt' => '2026-01-01 12:00:00',
        ]);
        $this->assertNotEmpty($response->json('errors'));

        // 2. Eloquent model mass-assignment rejects voice_consent_at
        $user = new User([
            'name' => 'Mass Assigner',
            'email' => 'mass@example.com',
            'password' => 'secret1234',
            'target_language' => 'en',
            'voice_consent_at' => '2026-01-01 12:00:00',
        ]);

        $this->assertNull($user->voice_consent_at);
    }

    public function test_voice_consent_at_is_exposed_on_me_query_after_the_mutation(): void
    {
        $user = User::factory()->withoutVoiceConsent()->create();
        Sanctum::actingAs($user);

        $this->graphQL(self::ME_QUERY)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.me.voiceConsentAt', null);

        $timestamp = Date::parse('2026-09-15 14:30:00');
        Date::setTestNow($timestamp);

        $this->graphQL(self::RECORD_CONSENT)->assertGraphQLErrorFree();
        Date::setTestNow();

        $response = $this->graphQL(self::ME_QUERY)
            ->assertGraphQLErrorFree();

        $this->assertSame(
            $timestamp->format('Y-m-d H:i:s'),
            $response->json('data.me.voiceConsentAt'),
        );
    }

    private function fakeStorage(User $user, string $fileUrl): AssessmentAudioStorage
    {
        $storage = Mockery::mock(AssessmentAudioStorage::class);
        $storage->shouldReceive('inspect')
            ->once()
            ->andReturn(new AudioUpload(
                key: "assessments/{$user->id}/recording.webm",
                fileUrl: $fileUrl,
                sizeBytes: 2048,
                contentType: 'audio/webm',
            ));

        return $storage;
    }
}
