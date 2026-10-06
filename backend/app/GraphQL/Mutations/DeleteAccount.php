<?php

namespace App\GraphQL\Mutations;

use App\Models\User;
use App\Privacy\AccountDeletionService;
use App\Privacy\ConfirmationRequired;
use App\Privacy\PasswordMismatch;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Hash;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class DeleteAccount
{
    public function __construct(
        private readonly AccountDeletionService $deletionService,
    ) {}

    /**
     * @param  array{password: string, confirmation: string}  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): bool
    {
        $user = $context->user();
        assert($user instanceof User);

        $confirmation = (string) ($args['confirmation'] ?? '');
        if ($confirmation !== 'DELETE') {
            $exception = new ConfirmationRequired('Confirmation must be DELETE.');

            throw new Error(
                $exception->getMessage(),
                extensions: ['code' => 'CONFIRMATION_REQUIRED'],
                previous: $exception,
            );
        }

        $password = (string) ($args['password'] ?? '');
        if (! Hash::check($password, $user->password)) {
            $exception = new PasswordMismatch('The provided password does not match.');

            throw new Error(
                $exception->getMessage(),
                extensions: ['code' => 'PASSWORD_MISMATCH'],
                previous: $exception,
            );
        }

        $this->deletionService->delete($user);

        $request = $context->request();
        if ($request?->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return true;
    }
}
