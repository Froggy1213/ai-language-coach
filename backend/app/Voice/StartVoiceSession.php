<?php

namespace App\Voice;

use App\Enums\LessonCardStatus;
use App\Enums\VoiceSessionStatus;
use App\Models\LessonCard;
use App\Models\User;
use App\Models\VoiceSession;
use GraphQL\Error\Error;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Starts a voice practice session (plan §5, `requestVoiceToken`).
 *
 * The order of the five steps matters and is the whole point of the resolver:
 * a learner must never get a token for a room no agent will enter, and a retry
 * (double-click, flaky network, React re-render) must never open a second room.
 */
final class StartVoiceSession
{
    public function __construct(
        private readonly LiveKitApi $api,
    ) {}

    /**
     * @param  array{lessonCardId: string}  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): VoiceSession
    {
        $user = $context->user();
        assert($user instanceof User);

        $card = $this->practisableCard($user, (string) $args['lessonCardId']);

        // 2. Idempotency guard. A session that is pending or active is still the
        //    learner's current conversation, so it is returned instead of a new
        //    one being created — the token is simply re-minted for its room.
        if ($existing = $this->currentSession($user, $card)) {
            return $existing;
        }

        return $this->start($user, $card);
    }

    /**
     * 1. The card must belong to the learner and be unlocked.
     *
     * A card in another roadmap is reported as not found rather than forbidden,
     * so the error does not confirm that somebody else's card exists.
     */
    private function practisableCard(User $user, string $lessonCardId): LessonCard
    {
        $card = LessonCard::query()
            ->whereKey($lessonCardId)
            ->whereHas('roadmap', static fn (Builder $query): Builder => $query->where('user_id', $user->getKey()))
            ->first();

        if (! $card instanceof LessonCard) {
            throw ValidationException::withMessages([
                'lessonCardId' => 'This lesson card does not exist.',
            ]);
        }

        if ($card->status !== LessonCardStatus::Ready) {
            throw ValidationException::withMessages([
                'lessonCardId' => 'This lesson card is not ready to practise yet.',
            ]);
        }

        return $card;
    }

    /**
     * @return VoiceSession|null the session the learner is already in, if any
     */
    private function currentSession(User $user, LessonCard $card): ?VoiceSession
    {
        $staleThreshold = (float) config('voice.agent.join_timeout_seconds') + 30.0;
        $staleBefore = Date::now()->subSeconds($staleThreshold);

        return $user->voiceSessions()
            ->where('lesson_card_id', $card->getKey())
            ->where(function (Builder $query) use ($staleBefore): void {
                $query->where('status', VoiceSessionStatus::Active)
                    ->orWhere(function (Builder $query) use ($staleBefore): void {
                        $query->where('status', VoiceSessionStatus::Pending)
                            ->where('created_at', '>=', $staleBefore);
                    });
            })
            ->latest('id')
            ->first();
    }

    /**
     * 3–4. Open the room, ask for a worker, and wait for it to arrive.
     */
    private function start(User $user, LessonCard $card): VoiceSession
    {
        try {
            $session = DB::transaction(function () use ($user, $card): VoiceSession {
                // Locking the user's row serialises concurrent start attempts for
                // one learner so that concurrent requests cannot race past the daily
                // session limit or open duplicate rooms for the same card.
                User::query()->whereKey($user->getKey())->lockForUpdate()->first();

                $staleThreshold = (float) config('voice.agent.join_timeout_seconds') + 30.0;
                $staleBefore = Date::now()->subSeconds($staleThreshold);

                $user->voiceSessions()
                    ->where('lesson_card_id', $card->getKey())
                    ->where('status', VoiceSessionStatus::Pending)
                    ->where('created_at', '<', $staleBefore)
                    ->update([
                        'status' => VoiceSessionStatus::Failed,
                        'fail_reason' => 'voice_start_stale',
                    ]);

                // Re-check idempotency under lock in case a concurrent request won the race.
                if ($existing = $this->currentSession($user, $card)) {
                    return $existing;
                }

                $this->ensureWithinDailyLimit($user);

                // The room name is derivable from a primary key on purpose: it is not a
                // secret, it is an address. What protects the room is the signed token.
                $session = $user->voiceSessions()->create([
                    'lesson_card_id' => $card->getKey(),
                    'status' => VoiceSessionStatus::Pending,
                    'room_name' => 'pending-'.Str::ulid(),
                ]);

                $session->room_name = 'lesson-'.$session->getKey();
                $session->save();

                return $session;
            });
        } catch (VoiceDailyLimitReached $exception) {
            throw new Error(
                $exception->getMessage(),
                extensions: ['code' => 'VOICE_DAILY_LIMIT_REACHED'],
                previous: $exception,
            );
        }

        // If the session was already created by a concurrent request, reuse it.
        if (! $session->wasRecentlyCreated) {
            return $session;
        }

        try {
            $this->api->createRoom($session->room_name);
            $this->api->dispatchAgent($session->room_name, $this->jobMetadata($session, $card, $user));
            $this->waitForAgent($session->room_name);
        } catch (VoiceFleetBusy $exception) {
            // The room exists and the learner was never told about it. It is not
            // closed here: LiveKit's own empty timeout (VOICE_ROOM_EMPTY_TIMEOUT)
            // is the backstop, and it costs nothing but an idle room until then.
            // Marking the session failed is what keeps the idempotency guard from
            // handing this dead room back on the next attempt.
            $session->update([
                'status' => VoiceSessionStatus::Failed,
                'fail_reason' => 'voice_fleet_busy',
            ]);

            throw new Error(
                $exception->getMessage(),
                extensions: ['code' => 'VOICE_FLEET_BUSY'],
                previous: $exception,
            );
        } catch (\Throwable $exception) {
            // A room livekit never opened, a dispatch it refused, a connection
            // that dropped: every one of those leaves a session that is still
            // `pending`, and the guard above would hand that session — and a
            // token for a room with nobody in it — straight back on the learner's
            // next attempt. Failing it here is what keeps the guard honest.
            $session->update([
                'status' => VoiceSessionStatus::Failed,
                'fail_reason' => 'voice_start_failed',
            ]);

            report($exception);

            $failure = VoiceStartFailed::forFailure($exception);

            throw new Error(
                $failure->getMessage(),
                extensions: ['code' => 'VOICE_START_FAILED'],
                previous: $failure,
            );
        }

        $session->update(['status' => VoiceSessionStatus::Active]);

        return $session;
    }

    /**
     * Enforce a per-user daily cap on voice sessions (plan §7).
     *
     * Every row created today counts towards the limit, including failed sessions:
     * creating a row dispatches a LiveKit room and voice agent, which incurs
     * infrastructure and API costs regardless of whether the call completed.
     *
     * Day boundary is UTC calendar day (from 00:00:00 UTC). All timestamps are
     * stored in UTC, and users.timezone is not populated during registration.
     */
    private function ensureWithinDailyLimit(User $user): void
    {
        $limit = (int) config('voice.daily_session_limit', 10);

        if ($limit <= 0) {
            return;
        }

        $count = $user->voiceSessions()
            ->where('created_at', '>=', Date::now('UTC')->startOfDay())
            ->count();

        if ($count >= $limit) {
            throw VoiceDailyLimitReached::forLimit($limit);
        }
    }

    /**
     * 4. Wait for the dispatched worker to appear in the room.
     *
     * LiveKit has no "tell me when a participant joins" call, so this polls.
     * Running out of time is the fleet being saturated — the dispatch was
     * accepted but no process was free to take it — which is reported as
     * VOICE_FLEET_BUSY rather than as a failure.
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
