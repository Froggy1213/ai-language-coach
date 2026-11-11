<?php

namespace App\GraphQL\Queries;

use App\Billing\VoiceAllowanceResolver;
use App\Models\User;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * The learner's plan, subscription status and voice-minute usage.
 *
 * The allowance resolver already treats the free tier as a plan, so the screen
 * reads the same shape before and after the first payment.
 */
final class BillingOverview
{
    public function __construct(private readonly VoiceAllowanceResolver $allowances) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        $user = $context->user();
        assert($user instanceof User);

        $allowance = $this->allowances->for($user);

        return [
            'planCode' => $allowance->planCode,
            'status' => $allowance->status,
            'interval' => $allowance->interval?->value,
            'includedMinutes' => $allowance->includedMinutes(),
            'usedMinutes' => $allowance->usedMinutes(),
            'remainingMinutes' => $allowance->remainingMinutes(),
            'resetsAt' => $allowance->resetsAt,
            'currentPeriodEnd' => $allowance->currentPeriodEnd,
            'cancelAtPeriodEnd' => $allowance->cancelAtPeriodEnd,
            'canManage' => $allowance->hasSubscription,
            'hasSubscription' => $allowance->hasSubscription,
        ];
    }
}
