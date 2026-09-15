<?php

namespace Database\Factories;

use App\Models\GrammarPoint;
use App\Models\ReviewItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewItem>
 */
class ReviewItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * SM-2 defaults for a freshly created item (plan §5).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'grammar_point_id' => GrammarPoint::factory(),
            'ease_factor' => 2.50,
            'interval_days' => 1,
            'repetition_number' => 0,
            'next_review_at' => now()->addDay(),
        ];
    }
}
