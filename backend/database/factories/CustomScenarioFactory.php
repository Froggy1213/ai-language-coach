<?php

namespace Database\Factories;

use App\Enums\CefrLevel;
use App\Models\CustomScenario;
use App\Models\User;
use App\Scenarios\ScenarioRegister;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomScenario>
 */
class CustomScenarioFactory extends Factory
{
    protected $model = CustomScenario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'language' => 'en',
            'code' => CustomScenario::generateCode(),
            'user_prompt' => 'Return a defective laptop at an electronics shop.',
            'title' => 'Returning a faulty laptop',
            'summary' => 'Explain a hardware issue at the service counter and ask for a replacement or refund.',
            'level' => CefrLevel::B1,
            'register' => ScenarioRegister::Neutral,
            'setting' => 'The service desk of a busy electronics store on Saturday afternoon.',
            'ai_role' => 'Store service clerk. Polite, but required to check store policy before approving a refund.',
            'ai_goal' => 'Find out what is wrong with the laptop, verify the receipt, and offer a repair first before agreeing to a replacement.',
            'learner_role' => 'Customer who bought a laptop three days ago that keeps shutting down.',
            'learner_goal' => 'Explain the problem clearly and get an immediate replacement or refund.',
            'complications' => [
                'Offer a two-week repair first instead of an immediate replacement.',
                'Ask whether the customer still has the original box and receipt.',
            ],
            'opening_line' => 'Hi there — how can I help you with your laptop today?',
            'success_criteria' => 'The symptom is explained clearly, the repair delay is declined, and a replacement or refund is agreed.',
            'useful_phrases' => [
                'I bought this laptop three days ago, and it keeps shutting down.',
                'I need it for work this week, so a two-week repair will not work for me.',
                'Could you replace it or give me a refund instead?',
            ],
            'confirmed_at' => now(),
        ];
    }

    public function unconfirmed(): static
    {
        return $this->state(fn (): array => [
            'confirmed_at' => null,
        ]);
    }
}
