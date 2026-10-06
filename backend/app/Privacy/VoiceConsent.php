<?php

namespace App\Privacy;

use App\Models\User;
use Illuminate\Support\Facades\Date;

final class VoiceConsent
{
    /**
     * Stamps the voice consent timestamp the first time audio is captured.
     * Never overwrites an existing timestamp.
     */
    public static function record(User $user): bool
    {
        if ($user->hasGivenVoiceConsent()) {
            return false;
        }

        $user->forceFill([
            'voice_consent_at' => Date::now(),
        ])->save();

        return true;
    }
}
