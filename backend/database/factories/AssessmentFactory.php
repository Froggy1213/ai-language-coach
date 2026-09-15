<?php

namespace Database\Factories;

use App\Enums\AssessmentStatus;
use App\Enums\CefrLevel;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assessment>
 */
class AssessmentFactory extends Factory
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
            'status' => AssessmentStatus::Processing,
            'cefr_level' => null,
            'audio_url' => fake()->url(),
            'raw_data' => null,
        ];
    }

    /**
     * Indicate that the async pipeline finished and produced a CEFR level.
     */
    public function done(CefrLevel $level = CefrLevel::B1): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AssessmentStatus::Done,
            'cefr_level' => $level,
        ]);
    }

    /**
     * Indicate that the async pipeline failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AssessmentStatus::Failed,
        ]);
    }
}
