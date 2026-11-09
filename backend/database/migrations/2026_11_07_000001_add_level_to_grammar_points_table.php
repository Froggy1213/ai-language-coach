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
        Schema::table('grammar_points', function (Blueprint $table): void {
            $table->string('level', 2)->nullable()->after('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grammar_points', function (Blueprint $table): void {
            $table->dropColumn('level');
        });
    }
};
