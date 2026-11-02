<?php

namespace App\Voice;

use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * The per-learner daily cap `requestVoiceToken` enforces (plan §7).
 */
final class VoiceSessionQuota
{
    /**
     * Enforce a per-user daily cap on voice sessions (plan §7).
     *
     * Every row created today counts towards the limit, including failed sessions:
     * creating a row dispatches a LiveKit room and voice agent, which incurs
     * infrastructure and API costs regardless of whether the call completed.
     *
     * Day boundary is UTC calendar day (from 00:00:00 UTC). All timestamps are
     * stored in UTC, and users.timezone is not populated during registration.
     *
     * @throws VoiceDailyLimitReached when the learner has used up today's sessions
     */
    public function ensureWithinDailyLimit(User $user): void
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
}
