<?php

namespace Database\Factories;

use App\Enums\CefrLevel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Tests and seeders start from a learner who has already consented;
            // scenarios that exercise the consent gate use withoutVoiceConsent().
            'voice_consent_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'target_language' => 'en',
            'current_level' => CefrLevel::A1,
            'timezone' => 'UTC',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * A learner who has not yet given the voice-recording consent.
     */
    public function withoutVoiceConsent(): static
    {
        return $this->state(fn (array $attributes) => [
            'voice_consent_at' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
