<?php

namespace Tests\Feature\Models;

use App\Enums\CefrLevel;
use App\Models\Assessment;
use App\Models\ReviewItem;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_casts_current_level_to_a_cefr_level(): void
    {
        $user = User::factory()->create(['current_level' => CefrLevel::B2]);

        $this->assertSame(CefrLevel::B2, $user->fresh()->current_level);
    }

    public function test_stores_the_target_language_and_timezone(): void
    {
        $user = User::factory()->create([
            'target_language' => 'en',
            'timezone' => 'Europe/Berlin',
        ]);

        $this->assertSame('en', $user->fresh()->target_language);
        $this->assertSame('Europe/Berlin', $user->fresh()->timezone);
    }

    public function test_exposes_its_learning_data(): void
    {
        $user = User::factory()->create();
        $roadmap = Roadmap::factory()->for($user)->create();
        $assessment = Assessment::factory()->for($user)->create();
        $reviewItem = ReviewItem::factory()->for($user)->create();

        $this->assertTrue($user->roadmap->is($roadmap));
        $this->assertTrue($user->assessments->contains($assessment));
        $this->assertTrue($user->reviewItems->contains($reviewItem));
    }

    public function test_deleting_a_user_removes_their_learning_data(): void
    {
        $user = User::factory()->create();
        $roadmap = Roadmap::factory()->for($user)->create();
        $assessment = Assessment::factory()->for($user)->create();

        $user->delete();

        $this->assertDatabaseMissing('roadmaps', ['id' => $roadmap->id]);
        $this->assertDatabaseMissing('assessments', ['id' => $assessment->id]);
    }
}
