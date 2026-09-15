<?php

namespace Tests\Feature\Models;

use App\Models\GrammarPoint;
use App\Models\ReviewItem;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReviewItemTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_applies_the_initial_sm2_defaults_when_the_caller_omits_them(): void
    {
        $user = User::factory()->create();
        $grammarPoint = GrammarPoint::factory()->create();

        DB::table('review_items')->insert([
            'user_id' => $user->id,
            'grammar_point_id' => $grammarPoint->id,
            'next_review_at' => now()->addDay(),
        ]);

        $item = ReviewItem::firstOrFail();

        $this->assertSame('2.50', $item->ease_factor);
        $this->assertSame(1, $item->interval_days);
        $this->assertSame(0, $item->repetition_number);
    }

    public function test_rejects_a_second_item_for_the_same_grammar_point(): void
    {
        $user = User::factory()->create();
        $grammarPoint = GrammarPoint::factory()->create();

        ReviewItem::factory()->for($user)->for($grammarPoint)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        ReviewItem::factory()->for($user)->for($grammarPoint)->create();
    }

    public function test_has_no_created_at_column(): void
    {
        $this->assertFalse(Schema::hasColumn('review_items', 'created_at'));
    }
}
