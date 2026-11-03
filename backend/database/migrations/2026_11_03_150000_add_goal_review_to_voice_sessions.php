<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The communication half of the after-conversation analysis (plan §9).
     *
     * A scenario session is reviewed against the scenario's learner goal, not
     * only for grammatical mistakes, so the verdict is stored beside the
     * transcript as JSON:
     * `{"goal_achieved": bool, "missed_expressions": ["..."]}`.
     *
     * Nullable because the review is best-effort: lesson sessions never have
     * one, and a scenario session keeps the column empty when the review model
     * fails or the session had no learner utterances.
     */
    public function up(): void
    {
        Schema::table('voice_sessions', function (Blueprint $table) {
            $table->json('goal_review')->nullable()->after('transcript');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voice_sessions', function (Blueprint $table) {
            $table->dropColumn('goal_review');
        });
    }
};
