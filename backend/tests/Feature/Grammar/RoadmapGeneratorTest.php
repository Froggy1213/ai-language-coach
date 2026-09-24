<?php

namespace Tests\Feature\Grammar;

use App\Enums\CefrLevel;
use App\Enums\LessonCardStatus;
use App\Enums\RoadmapStatus;
use App\Grammar\GrammarCatalogue;
use App\Grammar\RoadmapGenerator;
use App\Models\GrammarPoint;
use App\Models\User;
use Database\Seeders\GrammarPointSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RoadmapGeneratorTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_builds_a_roadmap_for_the_learners_current_band(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::B1]);

        $roadmap = $this->generator()->generate($user);

        $this->assertSame('English · A1–B1', $roadmap->title);
        $this->assertSame(RoadmapStatus::Active, $roadmap->status);
        $this->assertTrue($roadmap->user->is($user));
        $this->assertNotSame(0, $roadmap->lessonCards->count());
        $this->assertSame('present_simple', $roadmap->lessonCards->first()->grammarPoint->code);
        $this->assertSame(
            CefrLevel::B1,
            $this->catalogue()->find('en', $roadmap->lessonCards->last()->grammarPoint->code)->level,
        );
        $this->assertDatabaseHas('roadmaps', ['user_id' => $user->id, 'status' => RoadmapStatus::Active->value]);
    }

    public function test_it_orders_the_cards_the_way_they_should_be_studied(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::B2]);

        $cards = $this->generator()->generate($user)->lessonCards;

        $this->assertSame(range(1, $cards->count()), $cards->pluck('order_index')->all());

        $ranks = $cards
            ->map(fn ($card): int => $this->catalogue()->find('en', $card->grammarPoint->code)->level->rank())
            ->all();
        $weakestFirst = $ranks;
        sort($weakestFirst);

        $this->assertSame($weakestFirst, $ranks);
    }

    public function test_only_the_first_card_can_be_practised(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::A2]);

        $cards = $this->generator()->generate($user)->lessonCards;

        $this->assertSame(LessonCardStatus::Ready, $cards->first()->status);
        $this->assertSame(
            ['locked'],
            $cards->skip(1)->pluck('status')->map(static fn (LessonCardStatus $status): string => $status->value)->unique()->values()->all(),
        );
    }

    public function test_every_card_carries_the_catalogue_content_of_its_grammar_point(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::B1]);

        $cards = $this->generator()->generate($user)->lessonCards;

        foreach ($cards as $card) {
            $content = $this->catalogue()->find('en', $card->grammarPoint->code);

            $this->assertNotNull($content, "Card {$card->order_index} has a grammar point outside the catalogue.");
            $this->assertTrue(
                $content->level->isAtMost($user->current_level),
                "Card {$card->order_index} teaches {$content->level->value}, above the learner's band.",
            );
            $this->assertSame($content->cheatSheet->toArray(), $card->cheat_sheet);
            $this->assertSame($content->practicePrompt, $card->practice_prompt);
        }
    }

    public function test_it_returns_the_existing_roadmap_instead_of_building_a_second_one(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::B1]);

        $first = $this->generator()->generate($user);
        $second = $this->generator()->generate($user);

        $this->assertTrue($second->is($first));
        $this->assertDatabaseCount('roadmaps', 1);
        $this->assertSame($first->lessonCards->count(), $second->lessonCards()->count());
    }

    public function test_it_generates_a_roadmap_only_for_the_given_user(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en']);
        $otherUser = User::factory()->create(['target_language' => 'en']);

        $roadmap = $this->generator()->generate($user);

        $this->assertTrue($roadmap->user->is($user));
        $this->assertDatabaseMissing('roadmaps', ['user_id' => $otherUser->id]);
    }

    public function test_regenerating_archives_the_previous_roadmap_and_keeps_its_cards(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::A1]);

        $first = $this->generator()->generate($user);
        $user->update(['current_level' => CefrLevel::B1]);
        $second = $this->generator()->regenerate($user->fresh());

        $this->assertFalse($second->is($first));
        $this->assertSame(RoadmapStatus::Archived, $first->fresh()->status);
        $this->assertSame(RoadmapStatus::Active, $second->status);
        $this->assertTrue($user->fresh()->roadmap->is($second), 'The user relation must point at the active roadmap.');
        $this->assertDatabaseCount('roadmaps', 2);
        $this->assertTrue($first->fresh()->lessonCards->isNotEmpty(), 'Archiving a roadmap must not delete its cards.');
        $this->assertGreaterThan($first->lessonCards->count(), $second->lessonCards->count());
    }

    public function test_it_fails_when_a_catalogue_point_was_never_seeded(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'en', 'current_level' => CefrLevel::A1]);
        GrammarPoint::query()->where('language', 'en')->where('code', 'present_simple')->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Grammar point `en.present_simple` is in the catalogue but not in the database');

        $this->generator()->generate($user);
    }

    public function test_it_fails_when_the_language_has_no_catalogue(): void
    {
        $this->seed(GrammarPointSeeder::class);
        $user = User::factory()->create(['target_language' => 'es']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No grammar points are catalogued for `es`');

        $this->generator()->generate($user);
    }

    private function generator(): RoadmapGenerator
    {
        return $this->app->make(RoadmapGenerator::class);
    }

    private function catalogue(): GrammarCatalogue
    {
        return $this->app->make(GrammarCatalogue::class);
    }
}
