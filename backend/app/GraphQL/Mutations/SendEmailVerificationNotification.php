<?php

namespace App\GraphQL\Mutations;

use App\Models\User;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class SendEmailVerificationNotification
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): bool
    {
        $user = $context->user();
        assert($user instanceof User);

        if ($user->hasVerifiedEmail()) {
            return true;
        }

        $user->sendEmailVerificationNotification();

        return true;
    }
}
