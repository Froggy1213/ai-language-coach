<?php

namespace Database\Factories;

use App\Enums\VoiceSessionStatus;
use App\Models\LessonCard;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VoiceSession>
 */
class VoiceSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'lesson_card_id' => LessonCard::factory(),
            'room_name' => 'room-'.fake()->unique()->uuid(),
            'status' => VoiceSessionStatus::Pending,
            'fail_reason' => null,
            'duration_sec' => null,
            'transcript' => null,
        ];
    }
}
