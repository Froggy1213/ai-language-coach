<?php

namespace App\GraphQL\Mutations;

use App\Enums\LessonCardStatus;
use App\Models\LessonCard;
use App\Models\User;
use GraphQL\Error\Error;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class CompleteLessonCard
{
    /**
     * Complete a lesson card and unlock the next card in sequence (order_index + 1)
     * inside a database transaction under pessimistic locking (plan §5).
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
                ->whereHas('roadmap', static fn (Builder $query): Builder => $query->where('user_id', $user->getKey()))
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

            // Unlock the next card in sequence (order_index + 1) if one exists and is locked.
            $nextCard = LessonCard::query()
                ->where('roadmap_id', $card->roadmap_id)
                ->where('order_index', $card->order_index + 1)
                ->lockForUpdate()
                ->first();

            if ($nextCard instanceof LessonCard && $nextCard->status === LessonCardStatus::Locked) {
                $nextCard->update(['status' => LessonCardStatus::Ready]);
            }

            return $card->fresh();
        });
    }
}
