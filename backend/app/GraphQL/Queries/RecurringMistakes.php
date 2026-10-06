<?php

namespace App\GraphQL\Queries;

use App\Mistakes\RecurringMistake;
use App\Mistakes\RecurringMistakeDetector;
use App\Models\User;
use Illuminate\Support\Collection;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class RecurringMistakes
{
    public function __construct(private readonly RecurringMistakeDetector $detector) {}

    /**
     * @param  array<string, mixed>  $args
     * @return Collection<int, RecurringMistake>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Collection
    {
        // `@guard` guarantees an authenticated learner; the query is strictly owner-scoped.
        $user = $context->user();
        assert($user instanceof User);

        return $this->detector->detect($user);
    }
}
