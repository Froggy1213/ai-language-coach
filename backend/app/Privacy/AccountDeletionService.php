<?php

namespace App\Privacy;

use App\Models\User;
use App\Models\VoiceSession;
use Aws\S3\S3Client;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Schema\SchemaBuilder;
use Nuwave\Lighthouse\Subscriptions\Contracts\StoresSubscriptions;
use Nuwave\Lighthouse\Subscriptions\Subscriber;
use Throwable;

final class AccountDeletionService
{
    public function __construct(
        private readonly StoresSubscriptions $storage,
        private readonly S3Client $s3Client,
        private readonly ?SchemaBuilder $schemaBuilder = null,
    ) {}

    /**
     * Permanently deletes a user account, all associated data, subscriptions, and stored audio.
     */
    public function delete(User $user): void
    {
        $userId = $user->getKey();

        // 1. Inside a transaction, first clear the user's Lighthouse subscription
        //    subscribers, then delete the user row. README decision 19 explains why:
        //    a surviving subscriber for a deleted user causes ModelNotFoundException
        //    on the next broadcast, which takes the entire push down.
        DB::transaction(function () use ($user, $userId): void {
            $this->clearSubscribersForUser($user, (string) $userId);

            // MySQL foreign key order: voice_sessions references lesson_cards(id) without cascade,
            // while lesson_cards cascades from roadmaps(id), which cascades from users(id).
            // When deleting users, MySQL cascades roadmaps -> lesson_cards; if voice_sessions still
            // references lesson_cards, MySQL throws a 1451 constraint violation.
            // Deleting mistakes and voice_sessions first resolves this dependency cleanly.
            $user->mistakes()->delete();
            $user->voiceSessions()->delete();

            // Foreign key CASCADE deletes assessments, roadmaps, lesson_cards,
            // and review_items automatically.
            $user->delete();
        });

        // 2. Clear session and tokens for the user in HTTP contexts.
        $this->terminateAuthSession($userId);

        // 3. Delete user's assessment audio objects from S3.
        $this->deleteUserAudioObjects((string) $userId);
    }

    /**
     * Clears all Lighthouse subscription subscribers associated with the user across all topics.
     */
    public function clearSubscribersForUser(User $user, string $userId): void
    {
        $topics = $this->discoverTopics();

        foreach ($topics as $topic) {
            try {
                $subscribers = $this->storage->subscribersByTopic($topic);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($subscribers as $subscriber) {
                if ($this->subscriberBelongsToUser($subscriber, $user, $userId)) {
                    try {
                        $this->storage->deleteSubscriber($subscriber->channel);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            }
        }
    }

    /**
     * Discovers all schema-defined and known subscription topics.
     *
     * @return list<string>
     */
    private function discoverTopics(): array
    {
        $topics = [
            'ASSESSMENT_READY',
            'SESSION_FEEDBACK_READY',
        ];

        if ($this->schemaBuilder !== null) {
            try {
                $subscriptionType = $this->schemaBuilder->schema()->getSubscriptionType();
                if ($subscriptionType !== null) {
                    foreach (array_keys($subscriptionType->getFields()) as $fieldName) {
                        $topics[] = strtoupper(Str::snake($fieldName));
                    }
                }
            } catch (Throwable) {
                // Fall back to known topics
            }
        }

        return array_values(array_unique($topics));
    }

    private function subscriberBelongsToUser(Subscriber $subscriber, User $user, string $userId): bool
    {
        // Check 1: User resolved from subscriber's GraphQL context
        try {
            $contextUser = $subscriber->context->user();
            if ($contextUser instanceof User && (string) $contextUser->getKey() === $userId) {
                return true;
            }
        } catch (ModelNotFoundException) {
            // A subscriber whose user has already been deleted is orphaned and must be removed
            return true;
        } catch (Throwable) {
            // Context resolution failed; proceed to other checks
        }

        // Check 2: Direct argument match (e.g. userId argument on assessmentReady)
        if (isset($subscriber->args['userId']) && (string) $subscriber->args['userId'] === $userId) {
            return true;
        }

        // Check 3: Session argument match (e.g. sessionId argument on sessionFeedbackReady)
        if (isset($subscriber->args['sessionId'])) {
            $sessionId = (string) $subscriber->args['sessionId'];
            try {
                if (VoiceSession::query()->where('user_id', $userId)->whereKey($sessionId)->exists()) {
                    return true;
                }
            } catch (Throwable) {
                // Database query error; ignore
            }
        }

        return false;
    }

    private function terminateAuthSession(mixed $userId): void
    {
        try {
            if (auth()->guard('web')->id() === $userId || request()?->user()?->getKey() === $userId) {
                auth()->guard('web')->logout();

                $request = request();
                if ($request?->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function deleteUserAudioObjects(string $userId): void
    {
        $bucket = (string) config('filesystems.disks.s3.bucket');
        if ($bucket === '') {
            return;
        }

        $keyPrefix = (string) config('assessments.key_prefix', 'assessments');
        $prefix = "{$keyPrefix}/{$userId}/";

        try {
            $this->s3Client->deleteMatchingObjects($bucket, $prefix);
        } catch (Throwable $exception) {
            // An S3 failure must be reported, not resurrect the account or leave a half-deleted user.
            report($exception);
        }
    }
}
