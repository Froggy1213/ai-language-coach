<?php

namespace Tests\Feature\Scenarios;

use App\Enums\CefrLevel;
use App\Models\User;
use App\Scenarios\DeepSeekScenarioDrafter;
use App\Scenarios\ScenarioDraftFailed;
use App\Scenarios\ScenarioRegister;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

class DeepSeekScenarioDrafterTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private static function validResponse(): array
    {
        return [
            'title' => 'Returning a faulty laptop',
            'summary' => 'Explain a hardware issue at the service desk and ask for an immediate replacement.',
            'setting' => 'The service counter of a Tokyo electronics store on Saturday afternoon.',
            'ai_role' => 'Store service clerk. Polite, but required to offer a repair before a replacement.',
            'ai_goal' => 'Verify the receipt, inspect the issue, and offer a two-week repair first.',
            'learner_role' => 'Customer who bought a laptop three days ago that keeps shutting down.',
            'learner_goal' => 'Explain the problem clearly and get an immediate replacement or refund.',
            'complications' => [
                'Offer a two-week repair first instead of an immediate replacement.',
                'Ask whether the customer still has the original box and receipt.',
            ],
            'opening_line' => 'Good afternoon — how can I help you with your laptop today?',
            'success_criteria' => 'The symptom is explained clearly, the repair delay is declined, and a replacement is agreed.',
            'useful_phrases' => [
                'I bought this laptop three days ago, and it keeps shutting down.',
                'I need it for work this week, so a two-week repair will not work for me.',
                'Could you replace it or give me a refund instead?',
            ],
        ];
    }

    public function test_it_drafts_a_complete_scenario_from_a_learner_prompt(): void
    {
        StructuredAnonymousAgent::fake([self::validResponse()]);

        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::B1]);

        $draft = $this->drafter()->draft(
            $user,
            '壊れたノートPCをお店で返品・交換してもらう練習',
            CefrLevel::B1,
            ScenarioRegister::Formal,
        );

        $this->assertSame('Returning a faulty laptop', $draft->title);
        $this->assertSame(CefrLevel::B1, $draft->level);
        $this->assertSame(ScenarioRegister::Formal, $draft->register);
        $this->assertCount(2, $draft->complications);
        $this->assertCount(3, $draft->usefulPhrases);

        StructuredAnonymousAgent::assertPrompted('壊れたノートPCをお店で返品・交換してもらう練習');
        StructuredAnonymousAgent::assertPromptedTimes(1);
    }

    public function test_it_fails_when_complications_has_fewer_than_two_entries(): void
    {
        $payload = self::validResponse();
        $payload['complications'] = ['Only one complication.'];

        StructuredAnonymousAgent::fake([$payload]);

        $user = User::factory()->create(['target_language' => 'en']);

        $this->expectException(ScenarioDraftFailed::class);

        $this->drafter()->draft($user, 'Return a laptop', CefrLevel::B1, ScenarioRegister::Neutral);
    }

    public function test_it_fails_when_useful_phrases_has_fewer_than_three_entries(): void
    {
        $payload = self::validResponse();
        $payload['useful_phrases'] = ['One phrase.', 'Two phrases.'];

        StructuredAnonymousAgent::fake([$payload]);

        $user = User::factory()->create(['target_language' => 'en']);

        $this->expectException(ScenarioDraftFailed::class);

        $this->drafter()->draft($user, 'Return a laptop', CefrLevel::B1, ScenarioRegister::Neutral);
    }

    public function test_it_caps_complications_at_four_and_phrases_at_five(): void
    {
        $payload = self::validResponse();
        $payload['complications'] = ['One', 'Two', 'Three', 'Four', 'Five'];
        $payload['useful_phrases'] = ['P1', 'P2', 'P3', 'P4', 'P5', 'P6'];

        StructuredAnonymousAgent::fake([$payload]);

        $user = User::factory()->create(['target_language' => 'en']);

        $draft = $this->drafter()->draft($user, 'Return a laptop', CefrLevel::A2, ScenarioRegister::Casual);

        $this->assertCount(4, $draft->complications);
        $this->assertCount(5, $draft->usefulPhrases);
    }

    private function drafter(): DeepSeekScenarioDrafter
    {
        return $this->app->make(DeepSeekScenarioDrafter::class);
    }
}
