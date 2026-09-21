<?php

namespace App\GraphQL\Mutations;

use App\Models\User;
use Illuminate\Auth\AuthManager;

final class Register
{
    public function __construct(private readonly AuthManager $auth) {}

    /**
     * @param  array{name: string, email: string, password: string, targetLanguage: string}  $args
     */
    public function __invoke(mixed $root, array $args): User
    {
        $user = User::create([
            'name' => $args['name'],
            'email' => $args['email'],
            'password' => $args['password'],
            'target_language' => $args['targetLanguage'],
        ]);

        // `current_level` stays at its A1 default until the assessment sets it.
        $this->auth->guard('web')->login($user);

        return $user;
    }
}
