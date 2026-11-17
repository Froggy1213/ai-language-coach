<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Date;

final class EmailVerificationToken
{
    public static function makeUrl(User $user, ?int $expiresAt = null): string
    {
        $expires = $expiresAt ?? Date::now()->addMinutes(60)->getTimestamp();
        $id = (string) $user->getKey();
        $hash = sha1($user->getEmailForVerification());
        $signature = self::sign($id, $hash, $expires);

        return rtrim((string) config('app.frontend_url'), '/').'/verify-email?'.http_build_query([
            'id' => $id,
            'hash' => $hash,
            'expires' => $expires,
            'signature' => $signature,
        ]);
    }

    public static function sign(string $id, string $hash, int $expires): string
    {
        return hash_hmac('sha256', "{$id}|{$hash}|{$expires}", (string) config('app.key'));
    }

    public static function verifySignature(string $id, string $hash, int $expires, string $signature): bool
    {
        return hash_equals(self::sign($id, $hash, $expires), $signature);
    }
}
