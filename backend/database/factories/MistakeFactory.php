<?php

namespace Database\Factories;

use App\Models\GrammarPoint;
use App\Models\Mistake;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mistake>
 */
class MistakeFactory extends Factory
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
            'grammar_point_id' => GrammarPoint::factory(),
            // `mistakes.user_id` is denormalised for the (user, grammar point)
            // index, so derive it from the session to keep the pair consistent.
            'session_id' => fn (array $attributes): int => VoiceSession::factory()
                ->create(['user_id' => $attributes['user_id']])
                ->id,
            'user_utterance' => fake()->sentence(),
            'correction' => fake()->sentence(),
            'explanation' => fake()->sentence(),
        ];
    }
}
