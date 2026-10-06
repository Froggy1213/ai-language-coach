<?php

namespace Tests\Feature\Privacy;

use App\Assessments\AssessmentAudioStorage;
use App\Assessments\AudioUpload;
use App\Enums\LessonCardStatus;
use App\Models\LessonCard;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_voice_consent_is_stamped_on_the_first_assessment_submit(): void
    {
        $user = User::factory()->create(['voice_consent_at' => null]);
        Sanctum::actingAs($user);

        $fileUrl = "https://coach-audio.s3.amazonaws.com/assessments/{$user->id}/recording.webm";
        $storage = Mockery::mock(AssessmentAudioStorage::class);
        $storage->shouldReceive('inspect')
            ->once()
            ->with(Mockery::on(static fn (User $caller): bool => $caller->is($user)), $fileUrl)
            ->andReturn(new AudioUpload(
                key: "assessments/{$user->id}/recording.webm",
                fileUrl: $fileUrl,
                sizeBytes: 2048,
                contentType: 'audio/webm',
            ));
        $this->app->instance(AssessmentAudioStorage::class, $storage);

        $this->assertNull($user->voice_consent_at);
        $this->assertFalse($user->hasGivenVoiceConsent());

        $this->graphQL(self::SUBMIT_ASSESSMENT, ['audioUrl' => $fileUrl])
            ->assertGraphQLErrorFree();

        $user->refresh();
        $this->assertNotNull($user->voice_consent_at);
        $this->assertTrue($user->hasGivenVoiceConsent());
        $this->assertInstanceOf(Carbon::class, $user->voice_consent_at);
    }

    public function test_voice_consent_is_stamped_on_the_first_voice_session(): void
    {
        $user = User::factory()->create(['voice_consent_at' => null]);
        $roadmap = Roadmap::factory()->for($user)->create();
        $card = LessonCard::factory()->for($roadmap)->create(['status' => LessonCardStatus::Ready]);

        Sanctum::actingAs($user);

        Http::fake([
            'https://livekit.test/twirp/livekit.RoomService/CreateRoom' => Http::response(['sid' => 'RM_1']),
            'https://livekit.test/twirp/livekit.AgentDispatchService/CreateDispatch' => Http::response(['id' => 'DISP_1']),
            'https://livekit.test/twirp/livekit.RoomService/ListParticipants' => Http::response([
                'participants' => [
                    ['identity' => 'agent-1', 'kind' => 'AGENT'],
                ],
            ]),
        ]);

        $this->assertNull($user->voice_consent_at);

        $this->graphQL(self::REQUEST_VOICE_TOKEN, ['lessonCardId' => (string) $card->id])
            ->assertGraphQLErrorFree();

        $user->refresh();
        $this->assertNotNull($user->voice_consent_at);
        $this->assertTrue($user->hasGivenVoiceConsent());
    }

    public function test_the_original_consent_timestamp_survives_a_later_submission(): void
    {
        $originalConsent = Date::now()->subMonths(2);
        $user = User::factory()->create(['voice_consent_at' => $originalConsent]);
        Sanctum::actingAs($user);

        $fileUrl = "https://coach-audio.s3.amazonaws.com/assessments/{$user->id}/recording.webm";
        $storage = Mockery::mock(AssessmentAudioStorage::class);
        $storage->shouldReceive('inspect')
            ->once()
            ->with(Mockery::on(static fn (User $caller): bool => $caller->is($user)), $fileUrl)
            ->andReturn(new AudioUpload(
                key: "assessments/{$user->id}/recording.webm",
                fileUrl: $fileUrl,
                sizeBytes: 2048,
                contentType: 'audio/webm',
            ));
        $this->app->instance(AssessmentAudioStorage::class, $storage);

        $this->graphQL(self::SUBMIT_ASSESSMENT, ['audioUrl' => $fileUrl])
            ->assertGraphQLErrorFree();

        $user->refresh();
        $this->assertSame($originalConsent->toDateTimeString(), $user->voice_consent_at->toDateTimeString());
    }

    public function test_a_client_cannot_set_voice_consent_through_any_mutation_input(): void
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

    public function test_voice_consent_at_is_exposed_on_me_query(): void
    {
        $user = User::factory()->create(['voice_consent_at' => null]);
        Sanctum::actingAs($user);

        $response = $this->graphQL(self::ME_QUERY)
            ->assertGraphQLErrorFree();

        $this->assertNull($response->json('data.me.voiceConsentAt'));

        $timestamp = Date::parse('2026-09-15 14:30:00');
        $user->forceFill(['voice_consent_at' => $timestamp])->save();

        $responseWithConsent = $this->graphQL(self::ME_QUERY)
            ->assertGraphQLErrorFree();

        $this->assertSame($timestamp->format('Y-m-d H:i:s'), $responseWithConsent->json('data.me.voiceConsentAt'));
    }
}
