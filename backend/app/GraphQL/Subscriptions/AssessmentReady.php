<?php

namespace App\GraphQL\Subscriptions;

use App\Models\Assessment;
use Illuminate\Http\Request;
use Nuwave\Lighthouse\Schema\Types\GraphQLSubscription;
use Nuwave\Lighthouse\Subscriptions\Subscriber;

/**
 * Tells the waiting onboarding screen that its assessment finished (plan §4).
 *
 * The topic is the same for every listener — Lighthouse derives it from the
 * field name — so both the authorize and the filter step compare the requested
 * user id with the assessment's owner; without that, any learner could watch
 * somebody else's results.
 */
final class AssessmentReady extends GraphQLSubscription
{
    public function authorize(Subscriber $subscriber, Request $request): bool
    {
        $user = $request->user();

        return $user !== null
            && (string) $user->getKey() === (string) ($subscriber->args['userId'] ?? '');
    }

    public function filter(Subscriber $subscriber, mixed $root): bool
    {
        return $root instanceof Assessment
            && (string) $root->user_id === (string) ($subscriber->args['userId'] ?? '');
    }
}
