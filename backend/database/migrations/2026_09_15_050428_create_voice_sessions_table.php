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
        Schema::create('voice_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_card_id')->constrained();
            $table->string('room_name', 128)->unique('uq_room_name');
            $table->enum('status', ['pending', 'active', 'completed', 'failed', 'abandoned'])->default('pending');
            $table->string('fail_reason')->nullable();
            $table->unsignedInteger('duration_sec')->nullable();
            $table->json('transcript')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'idx_user_created');
            $table->index(['user_id', 'status'], 'idx_user_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voice_sessions');
    }
};
