<?php

namespace App\GraphQL\Resolvers;

use App\Models\User;
use App\Models\VoiceSession;
use App\Scenarios\ScenarioCatalogue;

/**
 * The scenario a voice session practises, read from the catalogue.
 *
 * Only `scenario_code` is stored on the session: the catalogue is the single
 * source of scenario content, so an edited scenario is served from the next
 * read rather than copied into rows that would drift. The language comes from
 * the session's user, which is what makes the lookup unambiguous for codes
 * that exist in more than one catalogue.
 *
 * A session whose user row is gone (or was eager-loaded without one) must
 * resolve to null instead of throwing: `lessonCard` is already optional, and a
 * nullable field is the right place for a missing subject.
 */
final class SessionScenario
{
    public function __construct(private readonly ScenarioCatalogue $catalogue) {}

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null
     */
    public function __invoke(?VoiceSession $session, array $args): ?array
    {
        if (! $session instanceof VoiceSession || $session->scenario_code === null) {
            return null;
        }

        $user = $session->user;
        if (! $user instanceof User) {
            return null;
        }

        $scenario = $this->catalogue->find($user->target_language, $session->scenario_code);

        if ($scenario === null) {
            return null;
        }

        $data = $scenario->toArray();
        // `toArray()` keeps `level` as its backed value; the native GraphQL
        // enum is backed by the PHP case, so it is restored here.
        $data['level'] = $scenario->level;

        return $data;
    }
}
