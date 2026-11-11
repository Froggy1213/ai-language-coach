<?php

namespace App\Billing;

use Carbon\CarbonImmutable;
use GraphQL\Error\ClientAware;
use RuntimeException;

/**
 * The learner has used every voice minute their plan includes.
 *
 * Client-aware because the message is meant for a learner. The GraphQL seam
 * adds the `VOICE_MINUTES_EXHAUSTED` code plus the numbers the paywall renders,
 * so the client never has to parse this sentence.
 */
final class VoiceMinutesExhausted extends RuntimeException implements ClientAware
{
    private function __construct(
        public readonly int $usedSeconds,
        public readonly int $includedSeconds,
        public readonly ?CarbonImmutable $resetsAt,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function fromAllowance(VoiceAllowance $allowance): self
    {
        return new self(
            usedSeconds: $allowance->usedSeconds,
            includedSeconds: $allowance->includedSeconds,
            resetsAt: $allowance->resetsAt,
            message: sprintf(
                'Voice minutes exhausted (%d of %d seconds used).',
                $allowance->usedSeconds,
                $allowance->includedSeconds,
            ),
        );
    }

    public function isClientSafe(): bool
    {
        return true;
    }
}
