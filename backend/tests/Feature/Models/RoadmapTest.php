<?php

namespace Tests\Feature\Models;

use App\Models\GrammarPoint;
use App\Models\LessonCard;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RoadmapTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_belongs_to_the_user_it_was_generated_for(): void
    {
        $user = User::factory()->create();
        $roadmap = Roadmap::factory()->for($user)->create();

        $this->assertTrue($roadmap->user->is($user));
    }

    public function test_holds_lesson_cards_linked_to_their_grammar_point(): void
    {
        $roadmap = Roadmap::factory()->create();
        $grammarPoint = GrammarPoint::factory()->create();
        $card = LessonCard::factory()->for($roadmap)->for($grammarPoint)->create();

        $this->assertTrue($roadmap->lessonCards->contains($card));
        $this->assertTrue($card->grammarPoint->is($grammarPoint));
    }

    public function test_casts_the_cheat_sheet_and_the_order_index(): void
    {
        $cheatSheet = ['summary' => 'Present perfect', 'examples' => ['I have eaten']];

        $card = LessonCard::factory()->create([
            'cheat_sheet' => $cheatSheet,
            'order_index' => 3,
        ]);

        $fresh = $card->fresh();

        $this->assertSame($cheatSheet, $fresh->cheat_sheet);
        $this->assertSame(3, $fresh->order_index);
    }
}
