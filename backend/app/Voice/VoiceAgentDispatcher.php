<?php

namespace App\Voice;

use App\Models\LessonCard;
use App\Models\User;
use App\Models\VoiceSession;
use App\Scenarios\ScenarioContent;
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
    public function dispatch(VoiceSession $session, PracticeSubject $subject, User $user): void
    {
        $this->api->createRoom($session->room_name);
        $this->api->dispatchAgent($session->room_name, $this->jobMetadata($session, $subject, $user));
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
     * Everything the agent needs to run the session without a second round trip,
     * gathered per subject kind:
     *
     * - a lesson gets the grammar point and the practice prompt, unchanged from
     *   before the scenario mode existed (plus `kind`);
     * - a scenario gets the role-play card — setting, both roles and goals, the
     *   register, the complications it may introduce, and the opening line.
     *
     * `useful_phrases` is deliberately absent: it is screen-only copy for the
     * learner's hint panel, and sending it would both pad the payload and invite
     * the model to read it out. The whole metadata blob must stay under 2 KB,
     * which is why nothing here is duplicated or pre-formatted.
     *
     * `target_language` and `level` describe the learner in both modes, so the
     * worker grades its language the same way for a lesson and a scenario.
     *
     * @return array<string, mixed>
     */
    private function jobMetadata(VoiceSession $session, PracticeSubject $subject, User $user): array
    {
        $common = [
            'session_id' => $session->getKey(),
            'room_name' => $session->room_name,
        ];

        $card = $subject->lessonCard;
        if ($card instanceof LessonCard) {
            return [
                ...$common,
                'grammar_point' => $card->grammarPoint->code,
                'grammar_point_id' => $card->grammar_point_id,
                'practice_prompt' => $card->practice_prompt,
                'target_language' => $user->target_language,
                'level' => $user->current_level->value,
                'kind' => 'lesson',
            ];
        }

        $scenario = $subject->scenario;
        assert($scenario instanceof ScenarioContent);

        return [
            ...$common,
            'kind' => 'scenario',
            'scenario_code' => $scenario->code,
            'setting' => $scenario->setting,
            'ai_role' => $scenario->aiRole,
            'ai_goal' => $scenario->aiGoal,
            'learner_role' => $scenario->learnerRole,
            'learner_goal' => $scenario->learnerGoal,
            'register' => $scenario->register->value,
            'complications' => $scenario->complications,
            'opening_line' => $scenario->openingLine,
            'target_language' => $user->target_language,
            'level' => $user->current_level->value,
        ];
    }
}
