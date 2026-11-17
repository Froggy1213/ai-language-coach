<?php

namespace App\GraphQL\Mutations;

use App\Models\User;
use App\Support\EmailVerificationToken;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Date;

final class VerifyEmail
{
    /**
     * @param  array{id: int|string, hash: string, expires: int, signature: string}  $args
     */
    public function __invoke(mixed $root, array $args): bool
    {
        $id = (string) $args['id'];
        $hash = $args['hash'];
        $expires = (int) $args['expires'];
        $signature = $args['signature'];

        if (! EmailVerificationToken::verifySignature($id, $hash, $expires, $signature)) {
            throw new Error(
                'Invalid email verification link.',
                extensions: ['code' => 'EMAIL_VERIFICATION_INVALID'],
            );
        }

        if ($expires < Date::now()->getTimestamp()) {
            throw new Error(
                'Email verification link has expired.',
                extensions: ['code' => 'EMAIL_VERIFICATION_EXPIRED'],
            );
        }

        $user = User::find($args['id']);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new Error(
                'Invalid email verification link.',
                extensions: ['code' => 'EMAIL_VERIFICATION_INVALID'],
            );
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return true;
    }
}
