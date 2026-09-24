<?php

namespace Tests\Feature\Console;

use App\Enums\CefrLevel;
use App\Enums\RoadmapStatus;
use App\Models\Roadmap;
use App\Models\User;
use Database\Seeders\GrammarPointSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GenerateRoadmapCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_generates_a_roadmap_for_a_user_found_by_email(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::A1]);

        $this->artisan('roadmap:generate', ['user' => $user->email])
            ->expectsOutputToContain('English · A1')
            ->assertSuccessful();

        $roadmap = Roadmap::query()->sole();
        $this->assertTrue($roadmap->user->is($user));
        $this->assertSame('present_simple', $roadmap->lessonCards->first()->grammarPoint->code);
    }

    public function test_it_finds_a_user_by_id(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en']);

        $this->artisan('roadmap:generate', ['user' => (string) $user->id])->assertSuccessful();

        $this->assertDatabaseHas('roadmaps', ['user_id' => $user->id]);
    }

    public function test_it_reports_a_user_that_does_not_exist(): void
    {
        $this->artisan('roadmap:generate', ['user' => 'missing@example.com'])
            ->expectsOutputToContain('No user matches')
            ->assertFailed();

        $this->assertDatabaseCount('roadmaps', 0);
    }

    public function test_it_replaces_the_active_roadmap_only_when_asked_to_regenerate(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::A1]);

        $this->artisan('roadmap:generate', ['user' => $user->email])->assertSuccessful();
        $this->artisan('roadmap:generate', ['user' => $user->email])->assertSuccessful();

        $this->assertDatabaseCount('roadmaps', 1);

        $user->update(['current_level' => CefrLevel::B1]);
        $this->artisan('roadmap:generate', ['user' => $user->email, '--regenerate' => true])->assertSuccessful();

        $this->assertDatabaseCount('roadmaps', 2);
        $this->assertSame(
            'English · A1',
            Roadmap::query()->whereBelongsTo($user)->where('status', RoadmapStatus::Archived)->sole()->title,
        );
        $this->assertSame('English · A1–B1', $user->fresh()->roadmap->title);
    }
}
