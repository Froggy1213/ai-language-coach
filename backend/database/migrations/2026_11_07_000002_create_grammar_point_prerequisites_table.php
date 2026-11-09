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
        Schema::create('grammar_point_prerequisites', function (Blueprint $table): void {
            $table->id();
            // Explicit short constraint names: the generated
            // `grammar_point_prerequisites_prerequisite_grammar_point_id_foreign`
            // is 65 characters, and MySQL rejects identifiers over 64.
            $table->foreignId('grammar_point_id')
                ->constrained('grammar_points', 'id', 'fk_point_prerequisites_point')
                ->cascadeOnDelete();
            $table->foreignId('prerequisite_grammar_point_id')
                ->constrained('grammar_points', 'id', 'fk_point_prerequisites_prereq')
                ->cascadeOnDelete();
            $table->unique(['grammar_point_id', 'prerequisite_grammar_point_id'], 'uq_point_prerequisite');
            $table->index('prerequisite_grammar_point_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grammar_point_prerequisites');
    }
};
