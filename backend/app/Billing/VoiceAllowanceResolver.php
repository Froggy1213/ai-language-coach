<?php

namespace App\Billing;

use App\Enums\BillingInterval;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Reads a learner's voice allowance from their subscription and their sessions.
 *
 * Nothing is stored as a "minutes used" counter: the sessions are the ledger,
 * and `duration_sec` is what the voice agent reports when a room finishes. A
 * counter would have to be kept in sync with every webhook, retry and abandoned
 * room; summing the sessions cannot drift from the sessions.
 */
final class VoiceAllowanceResolver
{
    public function __construct(private readonly PlanCatalogue $plans) {}

    public function for(User $user): VoiceAllowance
    {
        $subscription = $user->subscriptions()->latest('id')->first();

        if (! $subscription instanceof Subscription) {
            return $this->freeAllowance($user);
        }

        $plan = $this->plans->find($subscription->plan_code);

        // A subscription whose plan vanished from config cannot grant minutes.
        // Falling back to the free allowance is safer than granting a tier that
        // no longer exists; `billingOverview` still reports the subscription.
        if ($plan === null) {
            return $this->freeAllowance($user);
        }

        [$windowStart, $windowEnd] = $this->usageWindow($subscription);

        return new VoiceAllowance(
            planCode: $plan->code,
            status: $subscription->status,
            interval: BillingInterval::tryFrom($subscription->billing_interval),
            includedSeconds: $plan->monthlySeconds,
            usedSeconds: $this->usedSeconds($user, $windowStart, $windowEnd),
            resetsAt: $windowEnd,
            currentPeriodEnd: $subscription->current_period_end?->toImmutable(),
            cancelAtPeriodEnd: $subscription->cancel_at_period_end,
            hasSubscription: true,
        );
    }

    /**
     * Refuse a new session when the learner has no minutes left or the
     * subscription behind them is not in a paying state.
     *
     * @throws VoiceMinutesExhausted
     * @throws VoiceSubscriptionInactive
     */
    public function ensureCanStart(User $user): void
    {
        $allowance = $this->for($user);

        if ($allowance->hasSubscription && ! $allowance->isActive()) {
            throw VoiceSubscriptionInactive::forStatus((string) $allowance->status);
        }

        if ($allowance->isExhausted()) {
            throw VoiceMinutesExhausted::fromAllowance($allowance);
        }
    }

    private function freeAllowance(User $user): VoiceAllowance
    {
        return new VoiceAllowance(
            planCode: 'free',
            status: null,
            interval: null,
            includedSeconds: (int) config('billing.free_seconds', 900),
            usedSeconds: $this->usedSeconds($user),
            resetsAt: null,
            currentPeriodEnd: null,
            cancelAtPeriodEnd: false,
            hasSubscription: false,
        );
    }

    /**
     * The monthly allowance window that contains "now".
     *
     * The anchor is the subscription's first day, so a learner who subscribes
     * on the 20th gets a fresh allowance on the 20th rather than at a calendar
     * month boundary that would give them a short first period. A yearly
     * subscription is charged once but still resets monthly.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function usageWindow(Subscription $subscription): array
    {
        $anchor = $subscription->usage_anchor_at
            ?? $subscription->current_period_start
            ?? $subscription->created_at
            ?? now();

        $anchor = CarbonImmutable::instance($anchor);
        $now = CarbonImmutable::now();

        $months = $now->greaterThan($anchor)
            ? (int) floor($anchor->diffInMonths($now))
            : 0;

        $start = $anchor->addMonthsNoOverflow(max(0, $months));

        return [$start, $start->addMonthsNoOverflow(1)];
    }

    /**
     * Seconds the learner has already used in a window.
     *
     * A session that has not reported its final duration yet still consumes
     * allowance, otherwise ten parallel starts would all pass the check before
     * the first room finished. Its elapsed time is capped at the session limit,
     * which is the most it can ever cost.
     */
    private function usedSeconds(
        User $user,
        ?CarbonImmutable $windowStart = null,
        ?CarbonImmutable $windowEnd = null,
    ): int {
        $query = $user->voiceSessions();

        if ($windowStart !== null) {
            $query->where('created_at', '>=', $windowStart);
        }

        if ($windowEnd !== null) {
            $query->where('created_at', '<', $windowEnd);
        }

        $completed = (int) (clone $query)->sum('duration_sec');

        $sessionLimit = (int) config('voice.max_session_seconds', 900);

        $inFlight = (clone $query)
            ->whereNull('duration_sec')
            ->whereIn('status', ['pending', 'active'])
            ->get(['created_at'])
            ->sum(fn ($session): int => min(
                $sessionLimit,
                max(0, (int) $session->created_at->diffInSeconds(CarbonImmutable::now())),
            ));

        return $completed + (int) $inFlight;
    }
}
