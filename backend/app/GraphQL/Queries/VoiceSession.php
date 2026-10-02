<?php

namespace App\GraphQL\Queries;

use App\Models\User;
use App\Models\VoiceSession as VoiceSessionModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class VoiceSession
{
    /**
     * @param  array{id: string}  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?VoiceSessionModel
    {
        $user = $context->user();
        assert($user instanceof User);

        return $user->voiceSessions()->find($args['id']);
    }
}
