<?php

namespace App\GraphQL\Resolvers;

use App\Models\LessonCard;
use App\Models\Roadmap;
use Illuminate\Database\Eloquent\Collection;

/**
 * Returns the roadmap's lesson cards in study order with mistake signals hydrated.
 *
 * Replaces `@hasMany @orderBy` because Lighthouse's relation batch loader calls
 * `$parents->load(...)`, which replaces the preloaded collection with fresh
 * models lacking the hydrated mistake attributes and parent `roadmap` relation.
 */
final class RoadmapLessonCards
{
    /**
     * @param  array<string, mixed>  $args
     * @return Collection<int, LessonCard>
     */
    public function __invoke(Roadmap $roadmap, array $args): Collection
    {
        /** @var Collection<int, LessonCard> $cards */
        $cards = $roadmap->relationLoaded('lessonCards')
            ? $roadmap->lessonCards->sortBy('order_index')->values()
            : $roadmap->lessonCards()
                ->with('grammarPoint.prerequisites')
                ->orderBy('order_index')
                ->get();

        if ($cards->isEmpty()) {
            return $cards;
        }

        $cards->loadMissing('grammarPoint');

        foreach ($cards as $card) {
            $card->setRelation('roadmap', $roadmap);
        }

        $firstCard = $cards->first();
        if ($firstCard !== null && ! array_key_exists('recent_mistake_count', $firstCard->getAttributes())) {
            LessonCard::hydrateMistakeSignals($cards, (int) $roadmap->user_id);
        }

        return $cards;
    }
}
