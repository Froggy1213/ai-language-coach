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
        Schema::create('review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grammar_point_id')->constrained()->cascadeOnDelete();
            $table->decimal('ease_factor', 4, 2)->default(2.50);
            $table->unsignedInteger('interval_days')->default(1);
            $table->unsignedInteger('repetition_number')->default(0);
            $table->timestamp('next_review_at');
            // Created lazily on the first mistake for a (user, grammar point)
            // pair, so only updated_at is tracked (plan §5).
            $table->timestamp('updated_at')->nullable();

            $table->unique(['user_id', 'grammar_point_id'], 'uq_user_gp');
            $table->index(['user_id', 'next_review_at'], 'idx_user_review');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_items');
    }
};
