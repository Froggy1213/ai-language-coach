<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the endpoint the Python voice agent reports its own failures to
 * (plan §5).
 *
 * The agent runs on Fargate and talks to this service without a user session,
 * so the shared secret is what stands between the endpoint and anyone who can
 * reach it.
 *
 * An unset secret refuses every request instead of accepting them: a
 * misconfigured deployment must fail closed, not open.
 */
final class VerifyInternalSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('voice.internal_secret');

        if ($expected === '') {
            return response()->json(['message' => 'VOICE_INTERNAL_SECRET is not configured.'], 503);
        }

        $presented = (string) $request->header('X-Internal-Secret', '');

        if (! hash_equals($expected, $presented)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
