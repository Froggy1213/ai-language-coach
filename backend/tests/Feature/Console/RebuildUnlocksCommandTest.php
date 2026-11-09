<?php

namespace Tests\Feature\Console;

use App\Enums\CefrLevel;
use App\Enums\LessonCardStatus;
use App\Grammar\RoadmapGenerator;
use App\Models\GrammarPoint;
use App\Models\Mistake;
use App\Models\User;
use Database\Seeders\GrammarPointSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RebuildUnlocksCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_rebuilds_unlocks_from_dag_and_mistakes_for_active_roadmaps(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::A1]);
        $roadmap = $this->app->make(RoadmapGenerator::class)->generate($user);

        // Lock `be_and_there` (root) and record a mistake on `articles` (locked)
        $beCard = $roadmap->lessonCards()
            ->whereHas('grammarPoint', static fn ($q) => $q->where('code', 'be_and_there'))
            ->sole();
        $beCard->update(['status' => LessonCardStatus::Locked]);

        $articlesPoint = GrammarPoint::query()->where('language', 'en')->where('code', 'articles')->sole();
        Mistake::factory()->for($user)->for($articlesPoint)->create();

        $this->artisan('roadmap:rebuild-unlocks')
            ->expectsOutputToContain('2 card(s) unlocked')
            ->assertSuccessful();

        $this->assertSame(LessonCardStatus::Ready, $beCard->fresh()->status);

        $articlesCard = $roadmap->lessonCards()
            ->where('grammar_point_id', $articlesPoint->id)
            ->sole();
        $this->assertSame(LessonCardStatus::Ready, $articlesCard->status);
    }

    public function test_dry_run_reports_unlocks_without_persisting_changes(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::A1]);
        $roadmap = $this->app->make(RoadmapGenerator::class)->generate($user);

        $beCard = $roadmap->lessonCards()
            ->whereHas('grammarPoint', static fn ($q) => $q->where('code', 'be_and_there'))
            ->sole();
        $beCard->update(['status' => LessonCardStatus::Locked]);

        $this->artisan('roadmap:rebuild-unlocks', ['--dry-run' => true])
            ->expectsOutputToContain('1 card(s) would be unlocked')
            ->assertSuccessful();

        $this->assertSame(LessonCardStatus::Locked, $beCard->fresh()->status);
    }

    public function test_it_filters_by_user_and_fails_for_unknown_user(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::A1]);
        $this->app->make(RoadmapGenerator::class)->generate($user);

        $this->artisan('roadmap:rebuild-unlocks', ['--user' => $user->email])
            ->assertSuccessful();

        $this->artisan('roadmap:rebuild-unlocks', ['--user' => 'nobody@example.com'])
            ->expectsOutputToContain('No user matches')
            ->assertFailed();
    }
}
