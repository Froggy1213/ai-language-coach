<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mistakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('voice_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Never null: unmatched errors fall back to the per-language
            // "uncategorized" sentinel grammar point (plan §5).
            $table->foreignId('grammar_point_id')->constrained();
            $table->text('user_utterance');
            $table->text('correction');
            $table->text('explanation');
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'grammar_point_id', 'created_at'], 'idx_user_gp_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mistakes');
    }
};
