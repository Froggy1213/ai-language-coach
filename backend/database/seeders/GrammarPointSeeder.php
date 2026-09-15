<?php

namespace Database\Seeders;

use App\Models\GrammarPoint;
use Illuminate\Database\Seeder;

class GrammarPointSeeder extends Seeder
{
    /**
     * Seed the per-language sentinel grammar point.
     *
     * Every supported language needs exactly one `uncategorized` row, so that
     * an error the LLM cannot match to a real grammar point still has a valid
     * bucket and `mistakes.grammar_point_id` can stay NOT NULL (plan §5).
     *
     * Idempotent: safe to re-run after adding a language to config/languages.php.
     */
    public function run(): void
    {
        foreach (config('languages.supported') as $language) {
            GrammarPoint::updateOrCreate(
                [
                    'language' => $language,
                    'code' => GrammarPoint::UNCATEGORIZED_CODE,
                ],
                [
                    'title' => 'Uncategorized',
                    'category' => 'uncategorized',
                ],
            );
        }
    }
}
