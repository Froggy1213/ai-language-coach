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
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['processing', 'done', 'failed'])->default('processing');
            $table->enum('cefr_level', ['A1', 'A2', 'B1', 'B2', 'C1'])->nullable();
            $table->string('audio_url', 512);
            $table->json('raw_data')->nullable();
            $table->timestamp('created_at')->nullable();

            // MySQL reuses this composite index for the user_id foreign key,
            // so no separate single-column index is created.
            $table->index(['user_id', 'created_at'], 'idx_user_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
