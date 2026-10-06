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
        Schema::table('voice_sessions', function (Blueprint $table) {
            $table->index(['user_id', 'lesson_card_id', 'status'], 'idx_voice_sessions_user_card_status');
        });

        Schema::table('mistakes', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'idx_mistakes_user_created');
        });

        Schema::table('roadmaps', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'idx_roadmaps_user_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voice_sessions', function (Blueprint $table) {
            if (Schema::hasIndex('voice_sessions', 'idx_voice_sessions_user_card_status')) {
                $table->dropIndex('idx_voice_sessions_user_card_status');
            }
        });

        Schema::table('mistakes', function (Blueprint $table) {
            if (Schema::hasIndex('mistakes', 'idx_mistakes_user_created')) {
                $table->dropIndex('idx_mistakes_user_created');
            }
        });

        Schema::table('roadmaps', function (Blueprint $table) {
            if (Schema::hasIndex('roadmaps', 'idx_roadmaps_user_status')) {
                if (! Schema::hasIndex('roadmaps', 'roadmaps_user_id_foreign')) {
                    $table->index('user_id', 'roadmaps_user_id_foreign');
                }
                $table->dropIndex('idx_roadmaps_user_status');
            }
        });
    }
};
