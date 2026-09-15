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
        Schema::create('grammar_points', function (Blueprint $table) {
            $table->id();
            $table->string('language', 8);
            $table->string('code', 64);
            $table->string('title');
            $table->string('category', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['language', 'code'], 'uq_language_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grammar_points');
    }
};
