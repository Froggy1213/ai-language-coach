<?php

namespace App\Enums;

/**
 * Voice session lifecycle.
 *
 * Completed = the agent finished the scenario and closed the room itself.
 * Abandoned = the room closed on empty_timeout without the agent (plan §5).
 */
enum VoiceSessionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
}
