<?php

namespace Tests\Feature;

use App\Grammar\GrammarCatalogue;
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

        foreach (['en', 'es'] as $language) {
            $this->assertDatabaseHas('grammar_points', [
                'language' => $language,
                'code' => GrammarPoint::UNCATEGORIZED_CODE,
            ]);
        }
    }

    public function test_seeds_every_catalogue_entry_with_its_title_and_category(): void
    {
        config(['languages.supported' => ['en']]);

        $this->seed(GrammarPointSeeder::class);

        $entries = $this->catalogue()->forLanguage('en');

        foreach ($entries as $content) {
            $this->assertDatabaseHas('grammar_points', [
                'language' => 'en',
                'code' => $content->code,
                'title' => $content->title,
                'category' => $content->category,
            ]);
        }

        // Catalogue entries plus the one sentinel, and nothing else.
        $this->assertSame(count($entries) + 1, GrammarPoint::query()->where('language', 'en')->count());
    }

    public function test_a_language_without_a_catalogue_gets_only_its_sentinel(): void
    {
        config(['languages.supported' => ['es']]);

        $this->seed(GrammarPointSeeder::class);

        $this->assertSame(1, GrammarPoint::query()->where('language', 'es')->count());
    }

    public function test_seeding_twice_does_not_duplicate_anything(): void
    {
        config(['languages.supported' => ['en']]);

        $this->seed(GrammarPointSeeder::class);
        $this->seed(GrammarPointSeeder::class);

        $this->assertSame(count($this->catalogue()->forLanguage('en')) + 1, GrammarPoint::query()->count());
    }

    public function test_reseeding_refreshes_the_title_of_an_existing_point(): void
    {
        config(['languages.supported' => ['en']]);

        $this->seed(GrammarPointSeeder::class);
        GrammarPoint::query()->where('language', 'en')->where('code', 'present_simple')->update(['title' => 'Stale title']);

        $this->seed(GrammarPointSeeder::class);

        $this->assertSame(
            'Present Simple',
            GrammarPoint::query()->where('language', 'en')->where('code', 'present_simple')->sole()->title,
        );
    }

    private function catalogue(): GrammarCatalogue
    {
        return $this->app->make(GrammarCatalogue::class);
    }
}
