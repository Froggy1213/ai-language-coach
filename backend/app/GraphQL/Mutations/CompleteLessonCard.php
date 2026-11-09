<?php

namespace App\GraphQL\Mutations;

use App\Enums\LessonCardStatus;
use App\Enums\RoadmapStatus;
use App\Grammar\LessonUnlocks;
use App\Models\LessonCard;
use App\Models\User;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class CompleteLessonCard
{
    public function __construct(private readonly LessonUnlocks $unlocks) {}

    /**
     * Complete a lesson card and unlock any cards whose prerequisites are now
     * satisfied inside a database transaction under pessimistic locking (plan §5).
     *
     * @param  array{lessonCardId: string}  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): LessonCard
    {
        $user = $context->user();
        assert($user instanceof User);

        $lessonCardId = (string) $args['lessonCardId'];

        return DB::transaction(function () use ($user, $lessonCardId): LessonCard {
            $card = LessonCard::query()
                ->whereKey($lessonCardId)
                ->forUser($user)
                ->lockForUpdate()
                ->first();

            if (! $card instanceof LessonCard) {
                throw new Error(
                    'This lesson card does not exist.',
                    extensions: ['code' => 'LESSON_CARD_NOT_FOUND'],
                );
            }

            if ($card->status === LessonCardStatus::Locked) {
                throw new Error(
                    'This lesson card is locked.',
                    extensions: ['code' => 'LESSON_CARD_LOCKED'],
                );
            }

            // Move the card to completed if it was ready.
            if ($card->status === LessonCardStatus::Ready) {
                $card->update(['status' => LessonCardStatus::Completed]);
            }

            $roadmap = $card->roadmap()->lockForUpdate()->first();

            if ($roadmap !== null) {
                $this->unlocks->syncRoadmap($roadmap, completedCard: $card);

                $hasIncompleteCards = LessonCard::query()
                    ->where('roadmap_id', $card->roadmap_id)
                    ->whereIn('status', [LessonCardStatus::Locked, LessonCardStatus::Ready])
                    ->exists();

                if (! $hasIncompleteCards) {
                    $roadmap->update(['status' => RoadmapStatus::Completed]);
                }
            }

            return $card->fresh();
        });
    }
}
