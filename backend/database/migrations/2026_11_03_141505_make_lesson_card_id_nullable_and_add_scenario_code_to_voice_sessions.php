<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A voice session practises either a lesson card or a scenario, so the
     * lesson is no longer mandatory and the scenario code lives next to it.
     *
     * The foreign key on `lesson_card_id` survives `change()`: MySQL rewrites
     * the column definition in place and the constraint still references
     * `lesson_cards` — a nullable FK is valid. `scenario_code` deliberately has
     * no FK (plan D5): scenarios are versioned content files, and a scenario
     * removed from the catalogue must not break session history.
     *
     * The "exactly one subject" invariant is kept in `PracticeSubject` rather
     * than as a CHECK constraint, because only one class writes these rows.
     */
    public function up(): void
    {
        Schema::table('voice_sessions', function (Blueprint $table) {
            $table->foreignId('lesson_card_id')->nullable()->change();
            $table->string('scenario_code', 64)->nullable()->after('lesson_card_id');

            // Serves the per-subject idempotency lookup for scenario sessions.
            $table->index(['user_id', 'scenario_code'], 'idx_user_scenario');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Rolls back to lesson-only sessions. Turning `lesson_card_id` NOT NULL
     * again fails while scenario sessions are still present, which is the
     * intended signal: the old schema has nowhere to keep those rows.
     */
    public function down(): void
    {
        Schema::table('voice_sessions', function (Blueprint $table) {
            $table->dropIndex('idx_user_scenario');
            $table->dropColumn('scenario_code');
            $table->foreignId('lesson_card_id')->change();
        });
    }
};
