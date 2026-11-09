<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users', indexName: 'fk_custom_scenarios_user')
                ->cascadeOnDelete();
            $table->string('language', 16);
            $table->string('code', 64)->unique('uq_custom_scenarios_code');
            $table->text('user_prompt');
            $table->string('title', 160);
            $table->text('summary');
            $table->string('level', 8);
            $table->string('register', 16);
            $table->text('setting');
            $table->text('ai_role');
            $table->text('ai_goal');
            $table->text('learner_role');
            $table->text('learner_goal');
            $table->json('complications');
            $table->text('opening_line');
            $table->text('success_criteria');
            $table->json('useful_phrases');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'language', 'confirmed_at'], 'idx_custom_scenarios_user_lang');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_scenarios');
    }
};
