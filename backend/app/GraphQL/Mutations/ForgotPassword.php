<?php

namespace App\GraphQL\Mutations;

use Illuminate\Support\Facades\Password;

final class ForgotPassword
{
    /**
     * @param  array{email: string}  $args
     */
    public function __invoke(mixed $root, array $args): bool
    {
        Password::broker()->sendResetLink([
            'email' => trim($args['email']),
        ]);

        return true;
    }
}
