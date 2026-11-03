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
            'scenario_code' => null,
            'room_name' => 'room-'.fake()->unique()->uuid(),
            'status' => VoiceSessionStatus::Pending,
            'fail_reason' => null,
            'duration_sec' => null,
            'transcript' => null,
        ];
    }

    /**
     * A scenario session: the lesson column is empty and the code names the
     * scenario instead. Exactly one subject is filled, as `PracticeSubject`
     * enforces for rows the application writes itself.
     */
    public function scenario(string $code = 'free_talk'): static
    {
        return $this->state(fn (array $attributes): array => [
            'lesson_card_id' => null,
            'scenario_code' => $code,
        ]);
    }
}
