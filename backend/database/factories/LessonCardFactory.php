<?php

namespace Database\Factories;

use App\Enums\LessonCardStatus;
use App\Models\GrammarPoint;
use App\Models\LessonCard;
use App\Models\Roadmap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonCard>
 */
class LessonCardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'roadmap_id' => Roadmap::factory(),
            'grammar_point_id' => GrammarPoint::factory(),
            'order_index' => fake()->numberBetween(1, 20),
            'status' => LessonCardStatus::Locked,
            'cheat_sheet' => [
                'rule' => fake()->sentence(),
                'formula' => fake()->sentence(),
                'examples' => [fake()->sentence(), fake()->sentence()],
                'pitfalls' => [fake()->sentence()],
            ],
            'practice_prompt' => fake()->sentence(),
        ];
    }
}
