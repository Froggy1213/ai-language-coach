<?php

namespace App\GraphQL\Mutations;

use App\Models\GrammarPoint;
use App\Models\ReviewItem;
use App\Models\User;
use App\Review\Sm2;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class SubmitReviewResult
{
    public function __construct(private readonly Sm2 $sm2) {}

    /**
     * Recompute spaced repetition parameters for a grammar point review using SM-2 (plan §5).
     *
     * @param  array{grammarPointId: string, quality: int}  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ReviewItem
    {
        $user = $context->user();
        assert($user instanceof User);

        $grammarPointId = (string) $args['grammarPointId'];
        $quality = (int) $args['quality'];

        return DB::transaction(function () use ($user, $grammarPointId, $quality): ReviewItem {
            $item = ReviewItem::query()
                ->where('user_id', $user->getKey())
                ->where('grammar_point_id', $grammarPointId)
                ->lockForUpdate()
                ->first();

            if (! $item instanceof ReviewItem) {
                if (! GrammarPoint::query()->whereKey($grammarPointId)->exists()) {
                    throw new Error(
                        'This grammar point does not exist.',
                        extensions: ['code' => 'GRAMMAR_POINT_NOT_FOUND'],
                    );
                }

                // Review items are created lazily upon the first mistake for a
                // (user, grammar_point) pair (plan §5). If none exists, report
                // a typed domain error.
                throw new Error(
                    'No review item exists for this grammar point.',
                    extensions: ['code' => 'REVIEW_ITEM_NOT_FOUND'],
                );
            }

            return $this->sm2->apply($item, $quality);
        });
    }
}
