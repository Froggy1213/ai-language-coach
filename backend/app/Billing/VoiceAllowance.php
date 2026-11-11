<?php

namespace App\Billing;

use App\Enums\BillingInterval;
use Carbon\CarbonImmutable;

/**
 * What a learner may spend on voice practice right now.
 *
 * The free allowance is lifetime and has no reset date; a paid allowance is
 * monthly and resets on the subscription's anchor day, even when Stripe charges
 * yearly. Both are expressed the same way so the UI and the guard read one
 * shape.
 */
final readonly class VoiceAllowance
{
    public function __construct(
        public string $planCode,
        public ?string $status,
        public ?BillingInterval $interval,
        public int $includedSeconds,
        public int $usedSeconds,
        public ?CarbonImmutable $resetsAt,
        public ?CarbonImmutable $currentPeriodEnd,
        public bool $cancelAtPeriodEnd,
        public bool $hasSubscription,
    ) {}

    public function remainingSeconds(): int
    {
        return max(0, $this->includedSeconds - $this->usedSeconds);
    }

    public function includedMinutes(): int
    {
        return intdiv($this->includedSeconds, 60);
    }

    public function usedMinutes(): int
    {
        return intdiv($this->usedSeconds, 60);
    }

    public function remainingMinutes(): int
    {
        return intdiv($this->remainingSeconds(), 60);
    }

    public function isExhausted(): bool
    {
        return $this->remainingSeconds() <= 0;
    }

    /**
     * Whether the subscription behind this allowance is in a state that may
     * start a new session. The free plan has no subscription and is always
     * "active"; its limit is the allowance itself.
     */
    public function isActive(): bool
    {
        return $this->status === null || in_array($this->status, ['active', 'trialing'], true);
    }
}
