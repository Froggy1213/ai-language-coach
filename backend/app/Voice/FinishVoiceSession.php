<?php

namespace App\Voice;

use App\Models\User;
use App\Models\VoiceSession;
use GraphQL\Error\Error;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Throwable;

/**
 * Ends the session the learner just finished (plan §5, `finishVoiceSession`).
 *
 * Disconnecting the browser alone leaves the row `active` and the agent in the
 * room until the empty timeout closes it. Until then `/reflect` reports the
 * lesson as in progress, and when the timeout does arrive the session is
 * recorded as abandoned even though the learner ran the whole scenario.
 *
 * The resolver therefore moves the session itself before the client drops the
 * connection, and deletes the room so LiveKit delivers `room_finished` now
 * rather than minutes later.
 *
 * Idempotent: a session that is already terminal is returned untouched, so a
 * double click or a retried request cannot change an outcome that was already
 * written down.
 */
final class FinishVoiceSession
{
    public function __construct(
        private readonly VoiceSessionLifecycle $lifecycle,
        private readonly LiveKitApi $api,
    ) {}

    /**
     * @param  array{id: string}  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): VoiceSession
    {
        $user = $context->user();
        assert($user instanceof User);

        // Scoped to the authenticated learner: somebody else's session is
        // reported as not found rather than forbidden, so the error does not
        // confirm that it exists.
        $session = $user->voiceSessions()
            ->whereKey((string) $args['id'])
            ->first();

        if (! $session instanceof VoiceSession) {
            throw new Error(
                'Voice session not found.',
                extensions: ['code' => 'VOICE_SESSION_NOT_FOUND'],
            );
        }

        // Pressing finish twice (or retrying a response the browser never got)
        // must not move an already finished session again or delete its room a
        // second time.
        if ($session->status->isTerminal()) {
            return $session;
        }

        // The row's creation time is the only clock the backend trusts for when
        // the conversation started; `max(0, ...)` keeps a skewed clock from
        // producing a negative duration.
        $startedAt = $session->created_at;
        $duration = max(0, (int) ($startedAt?->diffInSeconds(Carbon::now()) ?? 0));

        $this->lifecycle->completed($session->getKey(), $duration);

        // Best effort: the session state is already correct, and LiveKit's empty
        // timeout is the backstop that closes a room we could not delete here.
        // A LiveKit outage must not turn a finished lesson into an error.
        try {
            $this->api->deleteRoom($session->room_name);
        } catch (Throwable $exception) {
            Log::warning('Could not delete the LiveKit room for a finished voice session.', [
                'session_id' => $session->getKey(),
                'room_name' => $session->room_name,
                'error' => $exception->getMessage(),
            ]);
        }

        return $session->refresh();
    }
}
