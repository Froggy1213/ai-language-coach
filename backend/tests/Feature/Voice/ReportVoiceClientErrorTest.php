<?php

namespace Tests\Feature\Voice;

use App\Enums\VoiceSessionStatus;
use App\Models\LessonCard;
use App\Models\Roadmap;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ReportVoiceClientErrorTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_rejects_unauthenticated_client_error_report(): void
    {
        $user = User::factory()->create();
        $session = $this->createSessionFor($user);

        $this->postJson("/api/sessions/{$session->getKey()}/client-error", [
            'stage' => 'webrtc_connect',
            'message' => 'could not establish pc connection',
        ])->assertUnauthorized();
    }

    public function test_logs_structured_warning_for_authenticated_owner(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $session = $this->createSessionFor($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sessions/{$session->getKey()}/client-error", [
                'stage' => 'webrtc_connect',
                'message' => 'could not establish pc connection',
            ])
            ->assertOk()
            ->assertJson(['logged' => true]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Voice session client error reported by browser.', [
                'session_id' => $session->getKey(),
                'room_name' => $session->room_name,
                'user_id' => $user->getKey(),
                'stage' => 'webrtc_connect',
                'error' => 'could not establish pc connection',
            ]);
    }

    public function test_sanitizes_crlf_in_message_before_logging(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $session = $this->createSessionFor($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sessions/{$session->getKey()}/client-error", [
                'stage' => 'webrtc_connect',
                'message' => "line 1\r\nline 2\tinjected",
            ])
            ->assertOk()
            ->assertJson(['logged' => true]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Voice session client error reported by browser.', [
                'session_id' => $session->getKey(),
                'room_name' => $session->room_name,
                'user_id' => $user->getKey(),
                'stage' => 'webrtc_connect',
                'error' => 'line 1 line 2 injected',
            ]);
    }

    public function test_returns_not_found_when_session_belongs_to_another_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $session = $this->createSessionFor($owner);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/sessions/{$session->getKey()}/client-error", [
                'stage' => 'webrtc_connect',
                'message' => 'could not establish pc connection',
            ])
            ->assertNotFound();
    }

    public function test_rejects_invalid_stage(): void
    {
        $user = User::factory()->create();
        $session = $this->createSessionFor($user);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sessions/{$session->getKey()}/client-error", [
                'stage' => 'arbitrary_stage',
                'message' => 'some error',
            ])
            ->assertJsonValidationErrors('stage');
    }

    private function createSessionFor(User $user): VoiceSession
    {
        $roadmap = Roadmap::factory()->for($user)->create();
        $card = LessonCard::factory()->for($roadmap)->create();

        return VoiceSession::factory()->for($user)->for($card, 'lessonCard')->create([
            'status' => VoiceSessionStatus::Active,
            'room_name' => 'lesson-42',
        ]);
    }
}
