<?php

namespace App\GraphQL\Queries;

use App\Models\Roadmap as RoadmapModel;
use App\Models\User;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class Roadmap
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?RoadmapModel
    {
        // `@guard` has already resolved the user. Going through the relation
        // rather than a bare `Roadmap` query keeps the answer to the roadmap the
        // learner is working through: regeneration archives the previous one
        // instead of deleting it, so an unfiltered `@first` could return it.
        $user = $context->user();
        assert($user instanceof User);

        return $user->roadmap;
    }
}
