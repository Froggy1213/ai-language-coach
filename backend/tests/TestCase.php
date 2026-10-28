<?php

namespace Tests;

use App\Enums\VoiceSessionStatus;
use App\Models\LessonCard;
use App\Models\Roadmap;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function sessionWithStatus(VoiceSessionStatus $status): VoiceSession
    {
        $user = User::factory()->create();
        $roadmap = Roadmap::factory()->for($user)->create();
        $card = LessonCard::factory()->for($roadmap)->create();

        return VoiceSession::factory()->for($user)->for($card, 'lessonCard')->create([
            'status' => $status,
        ]);
    }
}
