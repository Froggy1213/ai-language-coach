<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Privacy\AccountDeletionService;
use Illuminate\Console\Command;

final class DeleteUser extends Command
{
    protected $signature = 'privacy:delete-user {email}';

    protected $description = 'Delete a user account and all associated data on privacy request';

    public function handle(AccountDeletionService $deletionService): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->error("User with email `{$email}` not found.");

            return self::FAILURE;
        }

        $userId = $user->getKey();
        $deletionService->delete($user);

        $this->info("User `{$email}` (ID {$userId}) was permanently deleted.");

        return self::SUCCESS;
    }
}
