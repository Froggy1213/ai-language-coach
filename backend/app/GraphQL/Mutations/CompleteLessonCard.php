<?php

namespace App\GraphQL\Mutations;

use App\Enums\LessonCardStatus;
use App\Enums\RoadmapStatus;
use App\Grammar\LessonUnlocks;
use App\Models\LessonCard;
use App\Models\Roadmap;
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
            // Lock the active/completed roadmap before any lesson card so every
            // unlock transaction (here and in AnalyzeVoiceSessionMistakes)
            // acquires locks in the same `roadmaps -> lesson_cards` order.
            $roadmap = Roadmap::query()
                ->where('user_id', $user->getKey())
                ->where('status', '!=', RoadmapStatus::Archived)
                ->whereHas('lessonCards', static fn ($query) => $query->whereKey($lessonCardId))
                ->lockForUpdate()
                ->first();

            if (! $roadmap instanceof Roadmap) {
                throw new Error(
                    'This lesson card does not exist.',
                    extensions: ['code' => 'LESSON_CARD_NOT_FOUND'],
                );
            }

            $card = $roadmap->lessonCards()
                ->whereKey($lessonCardId)
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

            $this->unlocks->syncRoadmap($roadmap, completedCard: $card);

            $hasIncompleteCards = LessonCard::query()
                ->where('roadmap_id', $roadmap->getKey())
                ->whereIn('status', [LessonCardStatus::Locked, LessonCardStatus::Ready])
                ->exists();

            if (! $hasIncompleteCards) {
                $roadmap->update(['status' => RoadmapStatus::Completed]);
            }

            return $card->fresh();
        }, 3);
    }
}
