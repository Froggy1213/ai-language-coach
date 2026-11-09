<?php

namespace App\GraphQL\Mutations;

use App\Models\CustomScenario;
use App\Models\User;
use GraphQL\Error\Error;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class DeleteCustomScenario
{
    /**
     * @param  array{code: string}  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): bool
    {
        $user = $context->user();
        assert($user instanceof User);

        $code = trim($args['code']);

        $scenario = $user->customScenarios()
            ->where('language', $user->target_language)
            ->where('code', $code)
            ->first();

        if (! $scenario instanceof CustomScenario) {
            throw new Error(
                'This scenario does not exist.',
                extensions: ['code' => 'SCENARIO_NOT_FOUND'],
            );
        }

        $scenario->delete();

        return true;
    }
}
