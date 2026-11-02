<?php

namespace App\Voice;

use App\Models\LessonCard;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;

/**
 * Gets the LiveKit room and its agent ready for a freshly reserved session
 * (plan §5 steps 3–4).
 *
 * It owns every LiveKit call a start makes, in order: the room is created
 * explicitly, the worker is dispatched with the job metadata it needs, and the
 * room is polled until the agent shows up. Nothing is written to the session
 * row here — the caller owns the failure transitions, because they describe the
 * session, not LiveKit.
 */
final class VoiceAgentDispatcher
{
    public function __construct(private readonly LiveKitApi $api) {}

    /**
     * 3–4. Open the room, ask for a worker, and wait for it to arrive.
     *
     * @throws VoiceFleetBusy when LiveKit refuses the dispatch or no worker joins in time
     * @throws ConnectionException when LiveKit cannot be reached while creating the room or dispatching
     * @throws \RuntimeException when LiveKit answers a call with an error
     */
    public function dispatch(VoiceSession $session, LessonCard $card, User $user): void
    {
        $this->api->createRoom($session->room_name);
        $this->api->dispatchAgent($session->room_name, $this->jobMetadata($session, $card, $user));
        $this->waitForAgent($session->room_name);
    }

    /**
     * 4. Wait for the dispatched worker to appear in the room.
     *
     * LiveKit has no "tell me when a participant joins" call, so this polls.
     * Running out of time is the fleet being saturated — the dispatch was
     * accepted but no process was free to take it — which is reported as
     * VOICE_FLEET_BUSY rather than as a failure.
     *
     * @throws VoiceFleetBusy when no agent participant appears before the deadline
     */
    private function waitForAgent(string $roomName): void
    {
        $timeout = (float) config('voice.agent.join_timeout_seconds');
        $interval = (int) config('voice.agent.poll_interval_ms');
        $deadline = Date::now()->addSeconds($timeout);

        while (Date::now()->lessThan($deadline)) {
            try {
                if ($this->api->participants($roomName) !== []) {
                    return;
                }
            } catch (ConnectionException) {
                // Transient connection error during polling; continue polling until deadline.
            }

            Sleep::usleep($interval * 1000);
        }

        throw VoiceFleetBusy::forWaiting($timeout);
    }

    /**
     * 3. What the Python worker is told about the job.
     *
     * Everything the agent needs to run the sprint without a second round trip:
     * which session to report against, what to teach and how to push back when
     * the learner makes the mistake the card is about.
     *
     * @return array<string, mixed>
     */
    private function jobMetadata(VoiceSession $session, LessonCard $card, User $user): array
    {
        return [
            'session_id' => $session->getKey(),
            'room_name' => $session->room_name,
            'grammar_point' => $card->grammarPoint->code,
            'grammar_point_id' => $card->grammar_point_id,
            'practice_prompt' => $card->practice_prompt,
            'target_language' => $user->target_language,
            'level' => $user->current_level->value,
        ];
    }
}
