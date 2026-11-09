<?php

namespace App\GraphQL\Mutations;

use App\Models\User;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Records the learner's explicit consent to voice recording and processing.
 *
 * Consent used to be stamped implicitly by the first call or upload, which made
 * `voice_consent_at` a record of processing rather than of the learner's
 * decision. The UI now asks before the first recording or session and calls this
 * mutation; the start paths refuse without it.
 */
final class RecordVoiceConsent
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): User
    {
        $user = $context->user();
        assert($user instanceof User);

        $user->recordVoiceConsent();

        return $user->refresh();
    }
}
