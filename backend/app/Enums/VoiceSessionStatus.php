<?php

namespace App\Enums;

/**
 * Voice session lifecycle.
 *
 * Completed = the learner pressed "finish" (see `FinishVoiceSession`) or the
 *             agent finished the scenario and closed the room itself.
 * Abandoned = the room closed on empty_timeout without the agent (plan §5), or
 *             the idempotency guard found an `active` row whose agent had
 *             already exited and replaced it with a fresh room.
 */
enum VoiceSessionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
    case Failed = 'failed';
    case Abandoned = 'abandoned';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Abandoned], true);
    }
}
