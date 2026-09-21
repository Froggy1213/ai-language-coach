<?php

namespace App\GraphQL\Mutations;

use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Validation\ValidationException;

final class Login
{
    public function __construct(private readonly AuthManager $auth) {}

    /**
     * @param  array{email: string, password: string}  $args
     */
    public function __invoke(mixed $root, array $args): User
    {
        // The SPA writes to the session guard; Sanctum's own guard reads that
        // session first, so a cookie session needs no token to be issued.
        $guard = $this->auth->guard('web');

        if (! $guard->attempt(['email' => $args['email'], 'password' => $args['password']])) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = $guard->user();

        return $user;
    }
}
