<?php

namespace App\GraphQL\Mutations;

use App\Models\User;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class ResetPassword
{
    /**
     * @param  array{token: string, email: string, password: string}  $args
     */
    public function __invoke(mixed $root, array $args): bool
    {
        $status = Password::broker()->reset(
            [
                'email' => trim($args['email']),
                'password' => $args['password'],
                'password_confirmation' => $args['password'],
                'token' => $args['token'],
            ],
            function (User $user, #[\SensitiveParameter] string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new Error(
                'Invalid or expired password reset token.',
                extensions: ['code' => 'PASSWORD_RESET_INVALID'],
            );
        }

        return true;
    }
}
