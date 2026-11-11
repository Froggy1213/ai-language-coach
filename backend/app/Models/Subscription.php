<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One paid subscription as Stripe describes it.
 *
 * The row is a mirror of Stripe, not a source of truth: every webhook rewrites
 * it from the subscription payload, and the learner's entitlement is derived
 * from it plus the voice sessions it allows.
 */
#[Fillable([
    'user_id',
    'stripe_subscription_id',
    'stripe_price_id',
    'plan_code',
    'billing_interval',
    'status',
    'current_period_start',
    'current_period_end',
    'cancel_at_period_end',
    'usage_anchor_at',
    'ends_at',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /**
     * Statuses in which the learner may start a new voice session.
     *
     * `past_due` is deliberately absent: Stripe retries the charge on its own,
     * and a learner whose payment failed sees the billing portal instead of a
     * session that will be interrupted. A cancellation scheduled for the end of
     * the period keeps `active` until that date, so this list stays short.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = ['active', 'trialing'];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'usage_anchor_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
