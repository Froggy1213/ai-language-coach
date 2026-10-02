<?php

namespace App\GraphQL\Subscriptions;

use App\Models\VoiceSession;
use Illuminate\Http\Request;
use Nuwave\Lighthouse\Schema\Types\GraphQLSubscription;
use Nuwave\Lighthouse\Subscriptions\Subscriber;

/**
 * Tells the browser that a voice session's feedback and mistake analysis is ready (plan §4).
 *
 * The topic is the same for every listener — Lighthouse derives it from the
 * field name — so authorize() ensures the authenticated user owns the session
 * they are subscribing to, and filter() ensures only events for that session id
 * are delivered to the subscriber.
 */
final class SessionFeedbackReady extends GraphQLSubscription
{
    public function authorize(Subscriber $subscriber, Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        $sessionId = (string) ($subscriber->args['sessionId'] ?? '');

        if ($sessionId === '') {
            return false;
        }

        return VoiceSession::query()
            ->whereKey($sessionId)
            ->where('user_id', $user->getKey())
            ->exists();
    }

    public function filter(Subscriber $subscriber, mixed $root): bool
    {
        return $root instanceof VoiceSession
            && (string) $root->getKey() === (string) ($subscriber->args['sessionId'] ?? '');
    }
}
