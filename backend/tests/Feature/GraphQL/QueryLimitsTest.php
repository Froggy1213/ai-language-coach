<?php

namespace Tests\Feature\GraphQL;

use App\Enums\CefrLevel;
use App\Enums\LessonCardStatus;
use App\Models\GrammarPoint;
use App\Models\LessonCard;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class QueryLimitsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    /**
     * An artificial query with nesting depth 11 exceeds the default max depth limit (10)
     * and must be rejected with the standard Lighthouse / graphql-php error message.
     */
    public function test_artificial_query_deeper_than_limit_is_rejected(): void
    {
        // __type is a standard root query field whose ofType self-reference allows
        // constructing an arbitrary nesting depth without hitting relation cycles.
        $query = /** @lang GraphQL */ '
            query {
                __type(name: "User") {
                    ofType {
                        ofType {
                            ofType {
                                ofType {
                                    ofType {
                                        ofType {
                                            ofType {
                                                ofType {
                                                    ofType {
                                                        ofType {
                                                            ofType {
                                                                name
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        ';

        $this->graphQL($query)
            ->assertGraphQLErrorMessage('Max query depth should be 10 but got 11.');
    }

    /**
     * An artificial query with 60 selections exceeds the default max complexity limit (50)
     * and must be rejected before resolver execution.
     */
    public function test_artificial_query_exceeding_complexity_limit_is_rejected(): void
    {
        $fields = [];
        for ($i = 1; $i <= 60; $i++) {
            $fields[] = "a{$i}: __typename";
        }
        $query = 'query { '.implode(' ', $fields).' }';

        $response = $this->graphQL($query);

        $errorMessage = $response->json('errors.0.message');
        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('Max query complexity should be 50 but got 60.', $errorMessage);
    }

    /**
     * Real product queries (here ROADMAP_QUERY and ME_QUERY from documents.ts)
     * must pass without validation errors under the configured default limits.
     */
    public function test_representative_product_query_passes_at_default_limits(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'target_language' => 'en',
            'current_level' => CefrLevel::B1,
        ]);
        $roadmap = Roadmap::factory()->for($user)->create(['title' => 'A1 foundations']);
        $grammarPoint = GrammarPoint::factory()->create([
            'language' => 'en',
            'code' => 'present-simple',
            'title' => 'Present Simple',
            'category' => 'tenses',
        ]);
        LessonCard::factory()->for($roadmap)->for($grammarPoint)->create([
            'order_index' => 1,
            'status' => LessonCardStatus::Ready,
            'practice_prompt' => 'Talk about your routine',
        ]);

        Sanctum::actingAs($user);

        // ME_QUERY (complexity 7, depth 1)
        $this->graphQL(/** @lang GraphQL */ '
            query Me {
                me {
                    id
                    name
                    email
                    targetLanguage
                    currentLevel
                    voiceConsentAt
                }
            }
        ')
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.me.name', 'Ada');

        // ROADMAP_QUERY from documents.ts (complexity 19, depth 2)
        $this->graphQL(/** @lang GraphQL */ '
            query Roadmap {
                roadmap {
                    id
                    title
                    status
                    lessonCards {
                        id
                        orderIndex
                        status
                        practicePrompt
                        cheatSheet {
                            rule
                            formula
                            examples
                            pitfalls
                        }
                        grammarPoint {
                            id
                            code
                            title
                            category
                        }
                    }
                }
            }
        ')
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.roadmap.title', 'A1 foundations');
    }
}
