<?php

namespace App\Scenarios;

use App\Enums\CefrLevel;

/**
 * A structured role-play scenario card produced by the AI drafter before the
 * learner reviews and confirms its visible fields.
 */
final readonly class ScenarioDraft
{
    /**
     * @param  list<string>  $complications
     * @param  list<string>  $usefulPhrases
     */
    public function __construct(
        public string $title,
        public string $summary,
        public CefrLevel $level,
        public ScenarioRegister $register,
        public string $setting,
        public string $aiRole,
        public string $aiGoal,
        public string $learnerRole,
        public string $learnerGoal,
        public array $complications,
        public string $openingLine,
        public string $successCriteria,
        public array $usefulPhrases,
    ) {}
}
