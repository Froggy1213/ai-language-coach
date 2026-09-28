<?php

namespace Tests\Feature\Voice;

use App\Voice\LiveKitToken;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Tests\TestCase;

class LiveKitTokenTest extends TestCase
{
    private const SECRET = 'test-api-secret-that-is-long-enough';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'voice.livekit.api_key' => 'test-api-key',
            'voice.livekit.api_secret' => self::SECRET,
            'voice.livekit.token_ttl_minutes' => 15,
        ]);
    }

    public function test_a_participant_token_may_only_join_its_own_room(): void
    {
        $token = $this->tokens()->participant('lesson-42', 'learner-7');

        $video = $this->claims($token)['video'];

        $this->assertTrue($video->roomJoin);
        $this->assertSame('lesson-42', $video->room);
        $this->assertTrue($video->canPublish);
        $this->assertTrue($video->canSubscribe);
    }

    public function test_a_participant_token_carries_no_administrative_grant(): void
    {
        // The security boundary of the whole flow: a learner holding this token
        // must not be able to moderate a room, open one, or enumerate rooms.
        // Only the backend's own token gets those.
        $video = (array) $this->claims($this->tokens()->participant('lesson-42', 'learner-7'))['video'];

        $this->assertArrayNotHasKey('roomAdmin', $video);
        $this->assertArrayNotHasKey('roomCreate', $video);
        $this->assertArrayNotHasKey('roomList', $video);
    }

    public function test_a_server_token_carries_the_grants_the_api_calls_need(): void
    {
        $video = $this->claims($this->tokens()->server())['video'];

        $this->assertTrue($video->roomCreate);
        $this->assertTrue($video->roomList);
        $this->assertTrue($video->roomAdmin);
        $this->assertFalse($video->roomJoin ?? false);
    }

    public function test_the_token_expires_after_the_configured_ttl(): void
    {
        config(['voice.livekit.token_ttl_minutes' => 10]);

        $claims = $this->claims($this->tokens()->participant('lesson-42', 'learner-7'));

        // 600 seconds, not the SDK's six-hour default (plan §5).
        $this->assertSame(600, $claims['exp'] - $claims['iat']);
    }

    public function test_the_identity_is_the_subject_and_the_name_is_optional(): void
    {
        $named = $this->claims($this->tokens()->participant('lesson-42', 'learner-7', 'Learner'));

        $this->assertSame('learner-7', $named['sub']);
        $this->assertSame('Learner', $named['name']);

        $this->assertArrayNotHasKey('name', $this->claims($this->tokens()->participant('lesson-42', 'learner-7')));
    }

    public function test_it_refuses_to_mint_a_token_without_credentials(): void
    {
        config(['voice.livekit.api_secret' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LIVEKIT_API_KEY and LIVEKIT_API_SECRET must be set');

        $this->tokens()->participant('lesson-42', 'learner-7');
    }

    public function test_it_names_a_secret_that_is_too_short_for_hs256(): void
    {
        // php-jwt would otherwise fail with "Provided key is too short", which
        // does not tell an operator which setting to fix.
        config(['voice.livekit.api_secret' => 'too-short']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LIVEKIT_API_SECRET must be at least 32 characters');

        $this->tokens()->participant('lesson-42', 'learner-7');
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(string $token): array
    {
        return (array) JWT::decode($token, new Key(self::SECRET, 'HS256'));
    }

    private function tokens(): LiveKitToken
    {
        return new LiveKitToken(
            apiKey: (string) config('voice.livekit.api_key'),
            apiSecret: (string) config('voice.livekit.api_secret'),
        );
    }
}
