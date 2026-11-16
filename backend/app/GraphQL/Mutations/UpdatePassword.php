<?php

namespace App\GraphQL\Mutations;

use App\Models\User;
use App\Privacy\PasswordMismatch;
use GraphQL\Error\Error;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class UpdatePassword
{
    public function __construct(
        private readonly Request $request,
    ) {}

    /**
     * @param  array{currentPassword: string, newPassword: string}  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): bool
    {
        $user = $context->user();
        assert($user instanceof User);

        $currentPassword = (string) ($args['currentPassword'] ?? '');
        if (! Hash::check($currentPassword, $user->password)) {
            $exception = new PasswordMismatch('The provided password does not match.');

            throw new Error(
                $exception->getMessage(),
                extensions: ['code' => 'PASSWORD_MISMATCH'],
                previous: $exception,
            );
        }

        $user->forceFill([
            'password' => $args['newPassword'],
        ])->save();

        if ($this->request->hasSession()) {
            $this->request->session()->regenerate();
        }

        return true;
    }
}
