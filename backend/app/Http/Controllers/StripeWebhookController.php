<?php

namespace App\Http\Controllers;

use App\Billing\StripeWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;
use Throwable;

/**
 * Stripe's webhook endpoint.
 *
 * Deliberately outside the Sanctum guard: the caller is Stripe, and it proves
 * itself with a signature over the raw body. The raw body is what matters —
 * re-encoding the decoded JSON changes the bytes and invalidates the signature,
 * which is why the controller passes `getContent()` and not a re-serialised
 * array to `constructEvent`.
 */
final class StripeWebhookController
{
    public function __invoke(Request $request, StripeWebhookHandler $handler): JsonResponse
    {
        $secret = (string) config('billing.webhook_secret');

        if ($secret === '') {
            return response()->json(
                ['error' => 'Stripe webhook secret is not configured.'],
                503,
            );
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
                $secret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException $exception) {
            // A bad signature is not a server error: Stripe (or an attacker)
            // sent something this deployment did not sign.
            return response()->json(['error' => 'Invalid Stripe signature.'], 400);
        }

        try {
            $applied = $handler->handle($event->toArray());
        } catch (Throwable $exception) {
            // A 5xx is what makes Stripe retry. The transaction inside the
            // handler has already rolled back, so the retry is safe.
            report($exception);
            Log::error('Stripe webhook processing failed.', [
                'event_id' => $event->id,
                'type' => $event->type,
            ]);

            return response()->json(['error' => 'Webhook processing failed.'], 500);
        }

        return response()->json([
            'received' => true,
            'applied' => $applied,
        ]);
    }
}
