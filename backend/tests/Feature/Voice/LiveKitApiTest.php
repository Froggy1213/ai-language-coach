<?php

namespace Tests\Feature\Voice;

use App\Voice\LiveKitApi;
use App\Voice\LiveKitToken;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LiveKitApiTest extends TestCase
{
    private const SECRET = 'test-api-secret-that-is-long-enough';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'voice.livekit.url' => 'http://livekit.test:7880',
            'voice.livekit.api_key' => 'test-api-key',
            'voice.livekit.api_secret' => self::SECRET,
            'voice.livekit.token_ttl_minutes' => 15,
            'voice.livekit.server_token_ttl_seconds' => 60,
            'voice.livekit.api_timeout_seconds' => 5,
            'voice.agent.name' => 'ai-language-coach',
        ]);
    }

    public function test_dispatch_agent_authorizes_with_a_token_scoped_to_the_target_room(): void
    {
        Http::fake([
            'livekit.test:7880/twirp/livekit.AgentDispatchService/CreateDispatch' => Http::response(['id' => 'disp-1']),
        ]);

        $api = $this->api();
        $api->dispatchAgent('lesson-42', ['session_id' => 1]);

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/twirp/livekit.AgentDispatchService/CreateDispatch')) {
                return false;
            }

            $token = $this->bearerToken($request);
            $video = $this->claims($token)['video'];

            // LiveKit's AgentDispatchService/CreateDispatch refuses a token without
            // a room claim matching the room being dispatched to (HTTP 401 unauthenticated
            // "permissions denied"). The token must be explicitly room-scoped.
            return ($video->room ?? null) === 'lesson-42'
                && ($video->roomAdmin ?? false) === true
                && ($video->roomCreate ?? false) === true
                && ($video->roomList ?? false) === true;
        });
    }

    public function test_participants_authorizes_with_a_token_scoped_to_the_target_room(): void
    {
        Http::fake([
            'livekit.test:7880/twirp/livekit.RoomService/ListParticipants' => Http::response(['participants' => []]),
        ]);

        $api = $this->api();
        $api->participants('lesson-42');

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/twirp/livekit.RoomService/ListParticipants')) {
                return false;
            }

            $token = $this->bearerToken($request);
            $video = $this->claims($token)['video'];

            // LiveKit's RoomService/ListParticipants refuses an unscoped admin token;
            // it checks that the room claim in the video grant matches the requested room.
            return ($video->room ?? null) === 'lesson-42'
                && ($video->roomAdmin ?? false) === true;
        });
    }

    public function test_create_room_authorizes_with_an_unscoped_token(): void
    {
        Http::fake([
            'livekit.test:7880/twirp/livekit.RoomService/CreateRoom' => Http::response(['sid' => 'RM_1']),
        ]);

        $api = $this->api();
        $api->createRoom('lesson-42');

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/twirp/livekit.RoomService/CreateRoom')) {
                return false;
            }

            $token = $this->bearerToken($request);
            $video = $this->claims($token)['video'];

            // Room creation is the exception: the room does not exist yet,
            // so the server token carries only capability grants without a room claim.
            return ! isset($video->room)
                && ($video->roomCreate ?? false) === true;
        });
    }

    #[DataProvider('transportSchemes')]
    public function test_maps_transport_scheme_to_expected_http_endpoint(string $configuredUrl, string $expectedEndpoint): void
    {
        config(['voice.livekit.url' => $configuredUrl]);

        Http::fake([
            '*' => Http::response(['sid' => 'RM_1']),
        ]);

        $this->api()->createRoom('lesson-42');

        Http::assertSent(function (Request $request) use ($expectedEndpoint): bool {
            return $request->url() === $expectedEndpoint.'/twirp/livekit.RoomService/CreateRoom';
        });
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function transportSchemes(): array
    {
        return [
            'ws maps to http' => ['ws://livekit.test:7880', 'http://livekit.test:7880'],
            'ws maps to http with trailing slash' => ['ws://livekit.test:7880/', 'http://livekit.test:7880'],
            'wss maps to https' => ['wss://livekit.test:7880', 'https://livekit.test:7880'],
            'wss maps to https with trailing slash' => ['wss://livekit.test:7880/', 'https://livekit.test:7880'],
            'http passes through' => ['http://livekit.test:7880', 'http://livekit.test:7880'],
            'http passes through with trailing slash' => ['http://livekit.test:7880/', 'http://livekit.test:7880'],
            'https passes through' => ['https://livekit.test:7880', 'https://livekit.test:7880'],
            'https passes through with trailing slash' => ['https://livekit.test:7880/', 'https://livekit.test:7880'],
        ];
    }

    public function test_server_api_requests_use_the_configured_server_token_ttl(): void
    {
        config(['voice.livekit.server_token_ttl_seconds' => 45]);

        Http::fake([
            'livekit.test:7880/twirp/livekit.RoomService/CreateRoom' => Http::response(['sid' => 'RM_1']),
        ]);

        $this->api()->createRoom('lesson-42');

        Http::assertSent(function (Request $request): bool {
            $token = $this->bearerToken($request);
            $claims = $this->claims($token);

            return ($claims['exp'] - $claims['iat']) === 45;
        });
    }

    private function api(): LiveKitApi
    {
        $token = new LiveKitToken(
            apiKey: (string) config('voice.livekit.api_key'),
            apiSecret: (string) config('voice.livekit.api_secret'),
        );

        return new LiveKitApi($token);
    }

    private function bearerToken(Request $request): string
    {
        $header = $request->header('Authorization')[0] ?? '';
        $this->assertStringStartsWith('Bearer ', $header);

        return substr($header, 7);
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(string $jwt): array
    {
        return (array) JWT::decode($jwt, new Key(self::SECRET, 'HS256'));
    }
}
