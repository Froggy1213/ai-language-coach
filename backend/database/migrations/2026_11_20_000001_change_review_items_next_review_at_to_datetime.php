<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * MySQL `TIMESTAMP` caps out at 2038-01-19; `DATETIME` supports dates far
     * beyond that so long-lived SM-2 intervals can never fail with SQLSTATE[22007].
     */
    public function up(): void
    {
        Schema::table('review_items', function (Blueprint $table): void {
            $table->dateTime('next_review_at')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('review_items', function (Blueprint $table): void {
            $table->timestamp('next_review_at')->change();
        });
    }
};
