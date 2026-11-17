<?php

namespace App\GraphQL\Mutations;

use App\Models\User;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpdateProfile
{
    /**
     * @param  array{name: string, email: string}  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): User
    {
        $user = $context->user();
        assert($user instanceof User);

        $name = trim($args['name']);
        $email = trim($args['email']);
        $emailChanged = $user->email !== $email;

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->forceFill([
            'name' => $name,
            'email' => $email,
        ])->save();

        if ($emailChanged) {
            rescue(fn () => $user->sendEmailVerificationNotification(), report: true);
        }

        return $user->refresh();
    }
}
