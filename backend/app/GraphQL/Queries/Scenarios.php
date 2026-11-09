<?php

namespace App\GraphQL\Queries;

use App\Models\User;
use App\Scenarios\ScenarioCatalogue;
use App\Scenarios\ScenarioContent;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * The scenarios a learner can practise, narrowed to their target language.
 *
 * The catalogue already holds the content; this resolver only selects the
 * language. `toArray()` is the boundary that keeps `ai_goal` and
 * `complications` inside the backend: they are part of the array but not of
 * the `Scenario` type, so the SDL — not this class — is what prevents the
 * learner from reading the agent's goal and undermining the role-play.
 */
final class Scenarios
{
    public function __construct(private readonly ScenarioCatalogue $catalogue) {}

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        // `@guard` has already resolved the user; the target language decides
        // which catalogue file applies.
        $user = $context->user();
        assert($user instanceof User);

        return array_map(
            static fn (ScenarioContent $scenario): array => $scenario->toGraphQLArray(),
            $this->catalogue->forUser($user),
        );
    }
}
