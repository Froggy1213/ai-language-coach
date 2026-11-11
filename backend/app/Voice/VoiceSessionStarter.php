<?php

namespace App\Voice;

use App\Billing\VoiceAllowanceResolver;
use App\Billing\VoiceMinutesExhausted;
use App\Billing\VoiceSubscriptionInactive;
use App\Enums\VoiceSessionFailReason;
use App\Enums\VoiceSessionStatus;
use App\Models\LessonCard;
use App\Models\User;
use App\Models\VoiceSession;
use App\Scenarios\ScenarioContent;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reserves and provisions the session behind `requestVoiceToken` (plan §5).
 *
 * The reservation is one transaction: the learner's row is locked first so
 * concurrent starts cannot race past the daily cap or open duplicate rooms,
 * stale pending rows are retired, idempotency is re-checked under the lock, the
 * quota is applied, and the session row is written. Only once that commits is
 * the LiveKit side touched, and a failure there is written back to the row so
 * the idempotency guard never hands out a room nobody will enter.
 *
 * Everything subject-scoped — the stale cleanup and the idempotency re-check —
 * is scoped to the `PracticeSubject`, because a lesson and a scenario are
 * different conversations even though the daily quota is shared between them.
 *
 * Failures leave as domain exceptions, never as GraphQL errors: translating
 * them into the typed error contract is the resolver's job.
 */
final class VoiceSessionStarter
{
    public function __construct(
        private readonly VoiceSessionQuota $quota,
        private readonly VoiceAllowanceResolver $allowances,
        private readonly VoiceAgentDispatcher $dispatcher,
    ) {}

    /**
     * @throws VoiceDailyLimitReached when the learner has used up today's sessions
     * @throws VoiceMinutesExhausted when the plan's voice allowance is spent
     * @throws VoiceSubscriptionInactive when the subscription cannot fund a session
     * @throws VoiceFleetBusy when no agent takes the room
     * @throws VoiceStartFailed when room creation, dispatch or the agent wait fails
     */
    public function start(User $user, PracticeSubject $subject): VoiceSession
    {
        $session = DB::transaction(function () use ($user, $subject): VoiceSession {
            // Locking the user's row serialises concurrent start attempts for
            // one learner so that concurrent requests cannot race past the daily
            // session limit or open duplicate rooms for the same subject.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $staleThreshold = (float) config('voice.agent.join_timeout_seconds') + 30.0;
            $staleBefore = Date::now()->subSeconds($staleThreshold);

            $this->sessionsFor($user, $subject)
                ->where('status', VoiceSessionStatus::Pending)
                ->where('created_at', '<', $staleBefore)
                ->update([
                    'status' => VoiceSessionStatus::Failed,
                    'fail_reason' => VoiceSessionFailReason::VoiceStartStale->value,
                ]);

            // Re-check idempotency under lock in case a concurrent request won the race.
            if ($existing = $this->currentSession($user, $subject)) {
                return $existing;
            }

            $this->quota->ensureWithinDailyLimit($user);

            // The daily cap is anti-abuse; this is what the learner paid for.
            // Checked under the same lock, so two concurrent starts cannot both
            // spend the last remaining minute.
            $this->allowances->ensureCanStart($user);

            // The room name is derivable from a primary key on purpose: it is not a
            // secret, it is an address. What protects the room is the signed token.
            // Exactly one subject column is filled, so the "one subject" invariant
            // is written down rather than implied.
            $session = $user->voiceSessions()->create([
                'lesson_card_id' => $subject->lessonCard?->getKey(),
                'scenario_code' => $subject->scenario?->code,
                'status' => VoiceSessionStatus::Pending,
                'room_name' => 'pending-'.Str::ulid(),
            ]);

            $session->room_name = 'practice-'.$session->getKey();
            $session->save();

            return $session;
        });

        // If the session was already created by a concurrent request, reuse it.
        if (! $session->wasRecentlyCreated) {
            return $session;
        }

        try {
            $this->dispatcher->dispatch($session, $subject, $user);
        } catch (VoiceFleetBusy $exception) {
            // The room exists and the learner was never told about it. It is not
            // closed here: LiveKit's own empty timeout (VOICE_ROOM_EMPTY_TIMEOUT)
            // is the backstop, and it costs nothing but an idle room until then.
            // Marking the session failed is what keeps the idempotency guard from
            // handing this dead room back on the next attempt.
            $session->update([
                'status' => VoiceSessionStatus::Failed,
                'fail_reason' => VoiceSessionFailReason::VoiceFleetBusy->value,
            ]);

            Log::warning('Voice session start failed: fleet busy.', [
                'session_id' => $session->getKey(),
                'room_name' => $session->room_name,
                'subject' => $subject->label(),
            ]);

            throw $exception;
        } catch (\Throwable $exception) {
            // A room livekit never opened, a dispatch it refused, a connection
            // that dropped: every one of those leaves a session that is still
            // `pending`, and the guard above would hand that session — and a
            // token for a room with nobody in it — straight back on the learner's
            // next attempt. Failing it here is what keeps the guard honest.
            $session->update([
                'status' => VoiceSessionStatus::Failed,
                'fail_reason' => VoiceSessionFailReason::VoiceStartFailed->value,
            ]);

            report($exception);

            throw VoiceStartFailed::forFailure($exception);
        }

        $session->update(['status' => VoiceSessionStatus::Active]);

        return $session;
    }

    /**
     * The session the learner is already in for this subject, if any.
     *
     * Both the pre-lock guard in the resolver and the re-check under the lock
     * in `start()` ask this question, so it is defined once here. A session
     * counts as current while it is active, or pending and younger than the
     * join timeout plus a grace period — older pending rows are attempts that
     * never got an agent and must not block a fresh one.
     */
    public function currentSession(User $user, PracticeSubject $subject): ?VoiceSession
    {
        $staleThreshold = (float) config('voice.agent.join_timeout_seconds') + 30.0;
        $staleBefore = Date::now()->subSeconds($staleThreshold);

        return $this->sessionsFor($user, $subject)
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
     * The learner's sessions that practise this subject.
     *
     * Idempotency is per subject: an active lesson must not be handed back for
     * a scenario start, and the other way round. The daily quota is deliberately
     * *not* scoped here — it stays shared between both modes (plan §4.3).
     *
     * Scenario rows are matched with an explicit `whereNull('lesson_card_id')`
     * plus the code. `where('lesson_card_id', null)` would compile to `= NULL`
     * and never match anything, which is the trap this helper exists to avoid.
     *
     * @return HasMany<VoiceSession, User>
     */
    private function sessionsFor(User $user, PracticeSubject $subject): HasMany
    {
        $sessions = $user->voiceSessions();

        $card = $subject->lessonCard;
        if ($card instanceof LessonCard) {
            return $sessions->where('lesson_card_id', $card->getKey());
        }

        $scenario = $subject->scenario;
        assert($scenario instanceof ScenarioContent);

        return $sessions
            ->whereNull('lesson_card_id')
            ->where('scenario_code', $scenario->code);
    }
}
