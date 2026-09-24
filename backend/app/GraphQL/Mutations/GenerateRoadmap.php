<?php

namespace App\GraphQL\Mutations;

use App\Grammar\RoadmapGenerator;
use App\Models\Roadmap;
use App\Models\User;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class GenerateRoadmap
{
    public function __construct(private readonly RoadmapGenerator $generator) {}

    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Roadmap
    {
        // `@guard` has already resolved the user; generation is scoped to them
        // and never takes a user id from the client.
        $user = $context->user();
        assert($user instanceof User);

        return $this->generator->generate($user);
    }
}
