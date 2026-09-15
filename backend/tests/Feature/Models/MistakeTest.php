<?php

namespace Tests\Feature\Models;

use App\Models\Mistake;
use App\Models\VoiceSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MistakeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_belongs_to_the_session_named_by_the_session_id_column(): void
    {
        $session = VoiceSession::factory()->create();

        $mistake = Mistake::factory()->create([
            'session_id' => $session->id,
            'user_id' => $session->user_id,
        ]);

        $this->assertTrue($mistake->session->is($session));
    }

    public function test_factory_keeps_the_denormalised_user_in_sync_with_the_session(): void
    {
        $mistake = Mistake::factory()->create();

        $this->assertSame($mistake->session->user_id, $mistake->user_id);
    }

    public function test_is_append_only_with_no_updated_at_column(): void
    {
        $this->assertFalse(Schema::hasColumn('mistakes', 'updated_at'));
    }
}
