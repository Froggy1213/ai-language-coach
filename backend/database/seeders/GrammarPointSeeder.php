<?php

namespace Database\Seeders;

use App\Grammar\GrammarCatalogue;
use App\Grammar\GrammarPointContent;
use App\Models\GrammarPoint;
use Illuminate\Database\Seeder;

class GrammarPointSeeder extends Seeder
{
    public function __construct(private readonly GrammarCatalogue $catalogue) {}

    /**
     * Copy the versioned grammar catalogue into `grammar_points`.
     *
     * Every supported language gets one `uncategorized` sentinel, so that an
     * error the LLM cannot match to a real grammar point still has a valid
     * bucket and `mistakes.grammar_point_id` can stay NOT NULL (plan §5), plus
     * one row per catalogue entry — the ids `mistakes` and `lesson_cards` point
     * at, and the list the mistake-analysis prompt is restricted to.
     *
     * Idempotent: re-running refreshes titles, categories, levels, and
     * prerequisite edges in place. Rows for codes that have left the catalogue
     * are left alone, because mistakes may already point at them.
     */
    public function run(): void
    {
        foreach (config('languages.supported') as $language) {
            $this->seedSentinel($language);

            $contents = $this->catalogue->forLanguage($language);
            $pointsByCode = [];

            foreach ($contents as $content) {
                $pointsByCode[$content->code] = $this->seedContent($content);
            }

            foreach ($contents as $content) {
                $prereqIds = [];

                foreach ($content->prerequisites ?? [] as $prereqCode) {
                    if (isset($pointsByCode[$prereqCode])) {
                        $prereqIds[] = $pointsByCode[$prereqCode]->getKey();
                    }
                }

                $pointsByCode[$content->code]->prerequisites()->sync($prereqIds);
            }
        }
    }

    private function seedSentinel(string $language): GrammarPoint
    {
        return GrammarPoint::updateOrCreate(
            [
                'language' => $language,
                'code' => GrammarPoint::UNCATEGORIZED_CODE,
            ],
            [
                'title' => 'Uncategorized',
                'category' => 'uncategorized',
                'level' => null,
            ],
        );
    }

    private function seedContent(GrammarPointContent $content): GrammarPoint
    {
        return GrammarPoint::updateOrCreate(
            [
                'language' => $content->language,
                'code' => $content->code,
            ],
            [
                'title' => $content->title,
                'category' => $content->category,
                'level' => $content->level,
            ],
        );
    }
}
