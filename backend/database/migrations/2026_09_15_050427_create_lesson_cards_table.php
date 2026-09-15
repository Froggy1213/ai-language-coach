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
        Schema::create('lesson_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_id')->constrained()->cascadeOnDelete();
            // Grammar points are shared reference data: deleting one must not
            // silently take lesson cards with it.
            $table->foreignId('grammar_point_id')->constrained();
            $table->unsignedInteger('order_index');
            $table->enum('status', ['locked', 'ready', 'completed'])->default('locked');
            $table->json('cheat_sheet');
            $table->text('practice_prompt');
            $table->timestamps();

            $table->index(['roadmap_id', 'order_index'], 'idx_roadmap_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_cards');
    }
};
