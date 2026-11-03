<?php

namespace App\Scenarios;

/**
 * The tone a scenario is played in (plan §5).
 *
 * The register tells the voice agent how formal the AI's language should be;
 * it is content, not learner state, because the same learner may need a formal
 * client call and a casual free conversation on the same day.
 */
enum ScenarioRegister: string
{
    case Formal = 'formal';
    case Casual = 'casual';
    case Neutral = 'neutral';
}
