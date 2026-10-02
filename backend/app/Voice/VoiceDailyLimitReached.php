<?php

namespace App\Voice;

use GraphQL\Error\ClientAware;
use RuntimeException;

/**
 * The user has reached their daily voice session limit (plan §7).
 *
 * Client-aware because the message is written for the learner, explaining
 * the daily quota and when it resets.
 */
final class VoiceDailyLimitReached extends RuntimeException implements ClientAware
{
    public function isClientSafe(): bool
    {
        return true;
    }

    public static function forLimit(int $limit): self
    {
        $unit = $limit === 1 ? 'session' : 'sessions';

        return new self("Daily voice session limit reached ({$limit} {$unit} per day). Please try again tomorrow.");
    }
}
