<?php

namespace Database\Factories;

use App\Models\GrammarPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GrammarPoint>
 */
class GrammarPointFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'language' => 'en',
            'code' => fake()->unique()->slug(3),
            'title' => fake()->words(3, true),
            'category' => fake()->word(),
        ];
    }

    /**
     * The per-language fallback bucket for errors that match nothing (plan §5).
     */
    public function uncategorized(string $language = 'en'): static
    {
        return $this->state(fn (array $attributes): array => [
            'language' => $language,
            'code' => GrammarPoint::UNCATEGORIZED_CODE,
            'title' => 'Uncategorized',
            'category' => 'uncategorized',
        ]);
    }
}
