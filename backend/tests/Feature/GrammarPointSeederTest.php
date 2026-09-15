<?php

namespace Tests\Feature;

use App\Models\GrammarPoint;
use Database\Seeders\GrammarPointSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GrammarPointSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seeds_the_sentinel_point_for_every_supported_language(): void
    {
        config(['languages.supported' => ['en', 'es']]);

        $this->seed(GrammarPointSeeder::class);

        $this->assertDatabaseCount('grammar_points', 2);

        foreach (['en', 'es'] as $language) {
            $this->assertDatabaseHas('grammar_points', [
                'language' => $language,
                'code' => GrammarPoint::UNCATEGORIZED_CODE,
            ]);
        }
    }

    public function test_seeding_twice_does_not_duplicate_the_sentinel(): void
    {
        config(['languages.supported' => ['en']]);

        $this->seed(GrammarPointSeeder::class);
        $this->seed(GrammarPointSeeder::class);

        $this->assertDatabaseCount('grammar_points', 1);
    }
}
