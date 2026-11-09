<?php

namespace Tests\Feature\Scenarios;

use App\Enums\CefrLevel;
use App\Enums\VoiceSessionStatus;
use App\Mistakes\DeepSeekGoalReviewer;
use App\Models\CustomScenario;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\StructuredAnonymousAgent;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class CustomScenarioGraphQLTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    /**
     * @return array<string, mixed>
     */
    private static function aiDraftPayload(): array
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

    public function test_drafting_a_custom_scenario_stores_an_unconfirmed_draft_and_hides_ai_goal(): void
    {
        StructuredAnonymousAgent::fake([self::aiDraftPayload()]);

        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::B1]);
        Sanctum::actingAs($user);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation ($prompt: String!, $register: String, $level: CefrLevel) {
                draftCustomScenario(prompt: $prompt, register: $register, level: $level) {
                    code
                    title
                    summary
                    level
                    register
                    setting
                    aiRole
                    learnerRole
                    learnerGoal
                    usefulPhrases
                    isCustom
                    userPrompt
                }
            }
        ', [
            'prompt' => 'パソコンの初期不良でお店に交換をお願いする',
            'register' => 'formal',
            'level' => 'B1',
        ])
            ->assertGraphQLErrorFree();

        $draft = $response->json('data.draftCustomScenario');
        $this->assertMatchesRegularExpression('/^custom_[a-z0-9]+$/', $draft['code']);
        $this->assertSame('Returning a faulty laptop', $draft['title']);
        $this->assertSame('B1', $draft['level']);
        $this->assertSame('formal', $draft['register']);
        $this->assertTrue($draft['isCustom']);
        $this->assertSame('パソコンの初期不良でお店に交換をお願いする', $draft['userPrompt']);

        $row = CustomScenario::query()->sole();
        $this->assertNull($row->confirmed_at);
        $this->assertSame('Verify the receipt, inspect the issue, and offer a two-week repair first.', $row->ai_goal);

        // An unconfirmed draft does not appear in the scenarios list yet and cannot start a session.
        $this->graphQL(/** @lang GraphQL */ '
            query {
                scenarios { code isCustom }
            }
        ')
            ->assertGraphQLErrorFree()
            ->assertJsonCount(6, 'data.scenarios');

        $startResponse = $this->graphQL(/** @lang GraphQL */ '
            mutation ($scenarioCode: String) {
                requestVoiceToken(scenarioCode: $scenarioCode) { id }
            }
        ', ['scenarioCode' => $draft['code']]);

        $this->assertSame('SCENARIO_NOT_FOUND', $startResponse->json('errors.0.extensions.code'));
    }

    public function test_confirming_a_draft_saves_user_edits_exposes_it_in_scenarios_and_starts_a_voice_session(): void
    {
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::B1]);
        $draft = CustomScenario::factory()->for($user)->unconfirmed()->create([
            'title' => 'AI Draft Title',
        ]);

        Sanctum::actingAs($user);

        $confirmResponse = $this->graphQL(/** @lang GraphQL */ '
            mutation ($input: ConfirmCustomScenarioInput!) {
                confirmCustomScenario(input: $input) {
                    code
                    title
                    summary
                    level
                    register
                    setting
                    aiRole
                    learnerRole
                    learnerGoal
                    usefulPhrases
                    isCustom
                    userPrompt
                }
            }
        ', [
            'input' => [
                'code' => $draft->code,
                'title' => 'Laptop replacement at the store',
                'summary' => 'Ask the service counter to replace a defective laptop immediately.',
                'level' => 'B2',
                'register' => 'formal',
                'setting' => 'Service desk at a Flagship electronics store in Shinjuku.',
                'aiRole' => 'Store manager handling hardware returns.',
                'learnerRole' => 'Business traveller whose new laptop keeps crashing.',
                'learnerGoal' => 'Walk out with a working replacement today.',
                'usefulPhrases' => [
                    'It has crashed four times since yesterday.',
                    'I am flying out tomorrow morning, so I cannot wait for a repair.',
                    'Could you swap it for the same model in stock?',
                ],
            ],
        ])
            ->assertGraphQLErrorFree();

        $this->assertSame('Laptop replacement at the store', $confirmResponse->json('data.confirmCustomScenario.title'));
        $this->assertSame('B2', $confirmResponse->json('data.confirmCustomScenario.level'));
        $this->assertTrue($confirmResponse->json('data.confirmCustomScenario.isCustom'));
        $this->assertNotNull($draft->fresh()->confirmed_at);

        // Now appears in `scenarios` query for this user (6 built-in + 1 confirmed custom).
        $listResponse = $this->graphQL(/** @lang GraphQL */ '
            query {
                scenarios {
                    code
                    title
                    isCustom
                }
            }
        ')
            ->assertGraphQLErrorFree()
            ->assertJsonCount(7, 'data.scenarios');

        $codes = array_column($listResponse->json('data.scenarios'), 'code');
        $this->assertContains($draft->code, $codes);

        // Another user cannot see or start this user's custom scenario.
        $otherUser = User::factory()->create(['target_language' => 'en']);
        Sanctum::actingAs($otherUser);
        $this->graphQL(/** @lang GraphQL */ 'query { scenarios { code } }')
            ->assertJsonCount(6, 'data.scenarios');

        // Owner can start a voice session and review goals on their confirmed custom scenario.
        Sanctum::actingAs($user);
        config([
            'voice.livekit.url' => 'wss://livekit.test',
            'voice.livekit.api_key' => 'test-api-key',
            'voice.livekit.api_secret' => 'test-api-secret-that-is-long-enough',
            'voice.agent.join_timeout_seconds' => 5.0,
        ]);

        $dispatchedMetadata = null;
        Http::fake([
            'livekit.test/twirp/livekit.RoomService/CreateRoom' => Http::response(['sid' => 'RM_1']),
            'livekit.test/twirp/livekit.AgentDispatchService/CreateDispatch' => function ($request) use (&$dispatchedMetadata) {
                $dispatchedMetadata = json_decode((string) $request['metadata'], true, flags: JSON_THROW_ON_ERROR);

                return Http::response(['id' => 'dispatch-1']);
            },
            'livekit.test/twirp/livekit.RoomService/ListParticipants' => Http::response([
                'participants' => [['identity' => 'agent-1', 'kind' => 'AGENT']],
            ]),
        ]);

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($scenarioCode: String) {
                requestVoiceToken(scenarioCode: $scenarioCode) {
                    id
                    status
                    scenario { code title isCustom }
                }
            }
        ', ['scenarioCode' => $draft->code])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.requestVoiceToken.scenario.code', $draft->code)
            ->assertJsonPath('data.requestVoiceToken.scenario.title', 'Laptop replacement at the store')
            ->assertJsonPath('data.requestVoiceToken.scenario.isCustom', true);

        $this->assertIsArray($dispatchedMetadata);
        $this->assertSame('scenario', $dispatchedMetadata['kind']);
        $this->assertSame($draft->code, $dispatchedMetadata['scenario_code']);
        $this->assertSame('Walk out with a working replacement today.', $dispatchedMetadata['learner_goal']);
        $this->assertSame($draft->ai_goal, $dispatchedMetadata['ai_goal']);

        // Goal reviewer also works with custom scenarios.
        StructuredAnonymousAgent::fake([[
            'goal_achieved' => true,
            'missed_expressions' => [],
        ]]);

        $session = VoiceSession::query()->sole();
        $session->update([
            'status' => VoiceSessionStatus::Completed,
            'transcript' => [['turn_id' => 't1', 'transcript' => 'It has crashed four times since yesterday.']],
        ]);

        $goalReview = app(DeepSeekGoalReviewer::class)->review($session->fresh());
        $this->assertTrue($goalReview->goalAchieved);
    }

    public function test_a_user_can_delete_their_own_custom_scenario_but_not_someone_elses(): void
    {
        $owner = User::factory()->create(['target_language' => 'en']);
        $stranger = User::factory()->create(['target_language' => 'en']);
        $scenario = CustomScenario::factory()->for($owner)->create();

        Sanctum::actingAs($stranger);

        $forbidden = $this->graphQL(/** @lang GraphQL */ '
            mutation ($code: String!) {
                deleteCustomScenario(code: $code)
            }
        ', ['code' => $scenario->code]);

        $this->assertSame('SCENARIO_NOT_FOUND', $forbidden->json('errors.0.extensions.code'));
        $this->assertDatabaseHas('custom_scenarios', ['id' => $scenario->id]);

        Sanctum::actingAs($owner);

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($code: String!) {
                deleteCustomScenario(code: $code)
            }
        ', ['code' => $scenario->code])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.deleteCustomScenario', true);

        $this->assertDatabaseMissing('custom_scenarios', ['id' => $scenario->id]);
    }
}
