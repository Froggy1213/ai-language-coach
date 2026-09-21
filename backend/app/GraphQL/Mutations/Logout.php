<?php

namespace App\GraphQL\Mutations;

use Illuminate\Auth\AuthManager;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class Logout
{
    public function __construct(private readonly AuthManager $auth) {}

    public function __invoke(mixed $root, array $args, GraphQLContext $context): bool
    {
        $this->auth->guard('web')->logout();

        $request = $context->request();

        // A request authenticated by a personal access token carries no session.
        if ($request?->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return true;
    }
}
