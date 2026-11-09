<?php

namespace App\GraphQL\Queries;

use App\Models\Mistake;
use App\Models\ReviewItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * The authenticated learner's due review queue, with the mistake behind each item.
 *
 * `ReviewItem` only stores the (user, grammar point) pair; the sentence worth
 * recalling lives in `mistakes`. Resolving it through the item's `latestMistake`
 * field one row at a time would be an N+1, so the queue loads the newest mistake
 * per grammar point in a second batch query and attaches it to the item.
 */
final class DueReviews
{
    /**
     * @param  array<string, mixed>  $args
     * @return Collection<int, ReviewItem>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Collection
    {
        $user = $context->user();
        assert($user instanceof User);

        $items = $user->reviewItems()
            ->with('grammarPoint')
            ->due()
            ->get();

        if ($items->isEmpty()) {
            return $items;
        }

        $latestByGrammarPoint = $user->mistakes()
            ->whereIn('grammar_point_id', $items->pluck('grammar_point_id')->unique()->values())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique('grammar_point_id')
            ->keyBy('grammar_point_id');

        $items->each(static function (ReviewItem $item) use ($latestByGrammarPoint): void {
            $mistake = $latestByGrammarPoint->get($item->grammar_point_id);

            $item->setRelation('latestMistake', $mistake instanceof Mistake ? $mistake : null);
        });

        return $items;
    }
}
