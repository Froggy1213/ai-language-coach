<?php

namespace App\GraphQL\Queries;

use App\Models\Mistake;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class Mistakes
{
    /**
     * @param  array{grammarPointId?: string|null}  $args
     * @return Collection<int, Mistake>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Collection
    {
        // `@guard` has already resolved the user; going through the relation
        // keeps the query scoped to them whatever guard authenticated.
        $user = $context->user();
        assert($user instanceof User);

        return $user->mistakes()
            ->when(
                $args['grammarPointId'] ?? null,
                static fn (Builder $query, string $grammarPointId): Builder => $query->where('grammar_point_id', $grammarPointId),
            )
            ->latest('created_at')
            ->get();
    }
}
