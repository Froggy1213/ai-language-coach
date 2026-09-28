<?php

namespace App\Voice;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The LiveKit server API calls this project needs (plan §5 step 3–4).
 *
 * LiveKit exposes its server API as Twirp services over HTTP, and the whole
 * surface we need is three POSTs. Guzzle is already a dependency, so the
 * alternative — unbreaking the abandoned PHP SDK — would buy nothing.
 *
 * Each call carries the backend's own short-lived token as a bearer.
 */
final class LiveKitApi
{
    public function __construct(private readonly LiveKitToken $token) {}

    /**
     * Create the room the learner and the agent will meet in.
     *
     * Done explicitly rather than letting the first participant create it, so
     * `empty_timeout` and `max_participants` are decided here — the idle timeout
     * is what eventually closes an abandoned session, and the participant cap is
     * what makes a guessable room name harmless (the access token is the real
     * check).
     */
    public function createRoom(string $roomName): void
    {
        $this->call('livekit.RoomService/CreateRoom', [
            'name' => $roomName,
            'emptyTimeout' => (int) config('voice.room.empty_timeout_seconds'),
            'maxParticipants' => (int) config('voice.room.max_participants'),
        ]);
    }

    /**
     * Ask the agent fleet for a worker, telling it which lesson the job is for
     * (plan §2 — explicit dispatch with job metadata).
     *
     * The Python worker reads `metadata` off the job instead of calling back
     * into the API to discover what it is supposed to teach.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function dispatchAgent(string $roomName, array $metadata): void
    {
        $this->call('livekit.AgentDispatchService/CreateDispatch', [
            'agentName' => (string) config('voice.agent.name'),
            'room' => $roomName,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * The voice-agent participants in the room.
     *
     * This is the fleet-capacity check: a dispatched worker that found no free
     * process never joins, which is exactly what requestVoiceToken waits to
     * distinguish from a healthy start. Matching on the participant kind rather
     * than on an identity prefix means the agent's identity can be anything.
     *
     * @return list<string> identities of the agent participants in the room
     */
    public function participants(string $roomName): array
    {
        $response = $this->call('livekit.RoomService/ListParticipants', ['room' => $roomName]);

        /** @var list<array<string, mixed>> $participants */
        $participants = $response['participants'] ?? [];

        return array_values(array_filter(array_map(
            static fn (array $participant): ?string => ($participant['kind'] ?? null) === 'AGENT'
                ? (string) ($participant['identity'] ?? '')
                : null,
            $participants,
        )));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, array $payload): array
    {
        $apiKey = (string) config('voice.livekit.api_key');
        $apiSecret = (string) config('voice.livekit.api_secret');
        $url = (string) config('voice.livekit.url');

        if ($apiKey === '' || $apiSecret === '' || $url === '') {
            throw new RuntimeException('LIVEKIT_URL, LIVEKIT_API_KEY and LIVEKIT_API_SECRET must be set to call the LiveKit server API.');
        }

        $response = $this->request()
            ->post($this->httpEndpoint($url).'/twirp/'.$method, $payload);

        if ($response->status() === 429) {
            // LiveKit answers 429 when no worker could be assigned at all, which
            // is the same outcome the caller waits for below — the fleet is full.
            throw VoiceFleetBusy::forDispatchFailure(429);
        }

        if ($response->failed()) {
            throw new RuntimeException("LiveKit {$method} failed with HTTP {$response->status()}: ".$response->body());
        }

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json() ?? [];

        return $decoded;
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token->server())
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('voice.livekit.api_timeout_seconds'))
            ->retry(2, 100, function (\Throwable $exception): bool {
                // Only connection blips are worth a second attempt; a LiveKit
                // that answers with an error status answered deliberately.
                return $exception instanceof ConnectionException;
            }, throw: false);
    }

    /**
     * The token grants address an HTTP endpoint, while `LIVEKIT_URL` is the
     * WebSocket endpoint the browser dials (`wss://host`). They are the same
     * server on different schemes.
     */
    private function httpEndpoint(string $url): string
    {
        $endpoint = rtrim($url, '/');

        return match (true) {
            str_starts_with($endpoint, 'wss://') => 'https://'.substr($endpoint, 6),
            str_starts_with($endpoint, 'ws://') => 'http://'.substr($endpoint, 5),
            default => $endpoint,
        };
    }
}
