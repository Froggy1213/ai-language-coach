<?php

namespace App\Scenarios;

use App\Enums\CefrLevel;
use App\Models\User;

interface ScenarioDrafter
{
    /**
     * Expand the learner's short situation brief into a complete role-play card
     * with hidden AI goals/complications and visible scene fields for confirmation.
     *
     * @throws ScenarioDraftFailed when the model fails or returns an invalid structure
     */
    public function draft(
        User $user,
        string $userPrompt,
        CefrLevel $level,
        ScenarioRegister $register,
    ): ScenarioDraft;
}
