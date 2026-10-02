<?php

namespace App\Http\Controllers;

use App\Voice\InvalidWebhookSignature;
use App\Voice\LiveKitWebhook;
use App\Voice\VoiceSessionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives LiveKit room events (plan §5).
 *
 * Reports which session a room belongs to by its name — `lesson-{id}`, minted in
 * StartVoiceSession — so no separate room registry is needed.
 *
 * Nothing here trusts the payload: the signature is verified against the raw
 * body first, and only then is the event read, because a webhook endpoint that
 * acts on unverified input is an open door.
 */
final class LiveKitWebhookController extends Controller
{
    public function __construct(
        private readonly LiveKitWebhook $webhook,
        private readonly VoiceSessionLifecycle $lifecycle,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // read() returns the raw stream, not the decoded input: the signature
        // commits to these exact bytes.
        $rawBody = $request->getContent();

        try {
            $this->webhook->verify($request->header('Authorization') ?? '', $rawBody);
        } catch (InvalidWebhookSignature $exception) {
            report($exception);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        /** @var array<string, mixed> $event */
        $event = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

        $roomName = data_get($event, 'room.name');

        if (! is_string($roomName) || $roomName === '') {
            return response()->json(['message' => 'The event names no room.'], 422);
        }

        // `applied` distinguishes "this delivery moved the session" from "this
        // session was already in that state" — both are 200, because a duplicate
        // delivery is not an error and LiveKit must not retry it forever.
        $applied = $this->apply($event, $roomName);

        return response()->json(['applied' => $applied]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function apply(array $event, string $roomName): bool
    {
        return match ($event['event'] ?? null) {
            // The agent is a participant too, so its own arrival must not be
            // taken for the learner's — otherwise a session would look started
            // while the learner is still connecting.
            'participant_joined' => $this->isLearner($event)
                ? $this->lifecycle->participantJoined($roomName)
                : false,

            'room_finished' => $this->lifecycle->finished(
                roomName: $roomName,
                reason: is_string($event['roomEndReason'] ?? null) ? $event['roomEndReason'] : null,
                durationSeconds: VoiceSessionLifecycle::durationSeconds(
                    // Real LiveKit servers send `creationTime` (seconds, as a numeric
                    // string) on the room object, while older payloads named it
                    // `createdAt`. We prefer `creationTime` and fall back to `createdAt`.
                    roomCreatedAt: $this->timestamp($event['room']['creationTime'] ?? null)
                        ?? $this->timestamp($event['room']['createdAt'] ?? null),
                    roomFinishedAt: $this->timestamp($event['createdAt'] ?? null),
                ),
            ),

            // room_started leaves the session pending: the room exists, but
            // nobody has joined yet, so there is no conversation to be active.
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function isLearner(array $event): bool
    {
        return ($event['participant']['kind'] ?? null) !== 'AGENT';
    }

    private function timestamp(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
