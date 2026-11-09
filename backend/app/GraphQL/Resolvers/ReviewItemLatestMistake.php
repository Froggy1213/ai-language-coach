<?php

namespace App\GraphQL\Resolvers;

use App\Models\Mistake;
use App\Models\ReviewItem;

/**
 * The mistake a review item was born from, used as the recall prompt.
 *
 * `DueReviews` attaches the newest mistake per grammar point to the queue it
 * returns, which is the only place the field is normally read. The fallback
 * keeps the field correct for any other path that returns a `ReviewItem`
 * (the submit mutation does), at the cost of one query for that single row.
 */
final class ReviewItemLatestMistake
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(ReviewItem $item, array $args): ?Mistake
    {
        if ($item->relationLoaded('latestMistake')) {
            $attached = $item->getRelation('latestMistake');

            return $attached instanceof Mistake ? $attached : null;
        }

        return Mistake::query()
            ->where('user_id', $item->user_id)
            ->where('grammar_point_id', $item->grammar_point_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}
