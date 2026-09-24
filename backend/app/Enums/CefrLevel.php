<?php

namespace App\Enums;

/**
 * CEFR proficiency band. V1 covers A1–C1; C2 is out of scope (plan §0).
 */
enum CefrLevel: string
{
    case A1 = 'A1';
    case A2 = 'A2';
    case B1 = 'B1';
    case B2 = 'B2';
    case C1 = 'C1';

    /**
     * Position of the band on the A1–C1 scale, so bands can be compared and
     * sorted without a lookup table (roadmap generation, plan §6).
     */
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true) + 1;
    }

    /**
     * Whether this band is the same as or below the given one — "already
     * reached by the time the learner is at $ceiling".
     */
    public function isAtMost(self $ceiling): bool
    {
        return $this->rank() <= $ceiling->rank();
    }
}
