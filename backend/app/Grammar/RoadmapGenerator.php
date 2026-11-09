<?php

namespace App\Grammar;

use App\Enums\LessonCardStatus;
use App\Enums\RoadmapStatus;
use App\Models\GrammarPoint;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds the full knowledge-graph roadmap for a learner's target language (plan §5, §6).
 *
 * Every catalogue point across A1–C1 is included so the learner sees the entire
 * path ahead. Points below the learner's current CEFR band start `completed`;
 * points at or above their band whose prerequisites are satisfied (or on which
 * the learner has already made mistakes) start `ready`.
 *
 * Generation is idempotent and returns the existing active roadmap instead of
 * building a second one unless regeneration is explicitly requested.
 */
final class RoadmapGenerator
{
    public function __construct(
        private readonly GrammarCatalogue $catalogue,
        private readonly LessonUnlocks $unlocks,
    ) {}

    /**
     * The user's active roadmap, generated from their current level if they do
     * not have one yet.
     */
    public function generate(User $user): Roadmap
    {
        return $this->build($user, regenerate: false);
    }

    /**
     * Replace the active roadmap with one built for the user's current level.
     *
     * The previous roadmap is archived rather than deleted, so the mistakes and
     * voice sessions recorded against its lesson cards survive a re-assessment.
     */
    public function regenerate(User $user): Roadmap
    {
        return $this->build($user, regenerate: true);
    }

    private function build(User $user, bool $regenerate): Roadmap
    {
        $contents = $this->orderedContents($user->target_language);

        if ($contents === []) {
            throw new RuntimeException(
                "No grammar points are catalogued for `{$user->target_language}`, so no roadmap can be built for user {$user->getKey()}.",
            );
        }

        $grammarPointIds = $this->grammarPointIds($user->target_language, $contents);

        return DB::transaction(function () use ($user, $contents, $grammarPointIds, $regenerate): Roadmap {
            // Locking the user's own row serialises concurrent generation for
            // one learner, which a lock on `roadmaps` cannot do while the
            // roadmap does not exist yet.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $active = $user->roadmap()->lockForUpdate()->first();

            if ($active !== null && ! $regenerate) {
                return $active;
            }

            $active?->update(['status' => RoadmapStatus::Archived]);

            $mistakeCodes = $this->unlocks->mistakeCodesForUser($user->getKey(), $user->target_language);
            $statuses = $this->unlocks->initialStatuses(
                $user->target_language,
                $contents,
                $user->current_level,
                $mistakeCodes,
            );

            $roadmap = Roadmap::query()->create([
                'user_id' => $user->getKey(),
                'title' => $this->title($user, $contents),
                'status' => RoadmapStatus::Active,
            ]);

            $roadmap->lessonCards()->createMany($this->cards($contents, $grammarPointIds, $statuses));

            return $roadmap;
        });
    }

    /**
     * All catalogue entries for `$language`, ordered weakest CEFR band first
     * while preserving topological order within each band.
     *
     * @return list<GrammarPointContent>
     */
    private function orderedContents(string $language): array
    {
        $contents = $this->catalogue->forLanguage($language);

        usort(
            $contents,
            static fn (GrammarPointContent $a, GrammarPointContent $b): int => $a->level->rank() <=> $b->level->rank(),
        );

        return array_values($contents);
    }

    /**
     * Resolve catalogue codes to the ids that `lesson_cards` points at.
     *
     * @param  list<GrammarPointContent>  $contents
     * @return array<string, int>
     */
    private function grammarPointIds(string $language, array $contents): array
    {
        $codes = array_map(static fn (GrammarPointContent $content): string => $content->code, $contents);

        /** @var array<string, int> $ids */
        $ids = GrammarPoint::query()
            ->where('language', $language)
            ->whereIn('code', $codes)
            ->pluck('id', 'code')
            ->all();

        foreach ($codes as $code) {
            if (! isset($ids[$code])) {
                throw new RuntimeException(
                    "Grammar point `{$language}.{$code}` is in the catalogue but not in the database — run `php artisan db:seed --class=GrammarPointSeeder`.",
                );
            }
        }

        return $ids;
    }

    /**
     * Build the lesson card attributes with initial DAG statuses.
     *
     * @param  list<GrammarPointContent>  $contents
     * @param  array<string, int>  $grammarPointIds
     * @param  array<string, LessonCardStatus>  $statuses
     * @return list<array<string, mixed>>
     */
    private function cards(array $contents, array $grammarPointIds, array $statuses): array
    {
        $cards = [];

        foreach ($contents as $index => $content) {
            $cards[] = [
                'grammar_point_id' => $grammarPointIds[$content->code],
                'order_index' => $index + 1,
                'status' => $statuses[$content->code] ?? LessonCardStatus::Locked,
                'cheat_sheet' => $content->cheatSheet->toArray(),
                'practice_prompt' => $content->practicePrompt,
            ];
        }

        return $cards;
    }

    /**
     * @param  list<GrammarPointContent>  $contents
     */
    private function title(User $user, array $contents): string
    {
        $language = $user->targetLanguageName();
        $start = $user->current_level;
        $strongest = $contents[array_key_last($contents)]->level;

        return $start === $strongest || $start->rank() > $strongest->rank()
            ? "{$language} · {$strongest->value}"
            : "{$language} · {$start->value}–{$strongest->value}";
    }
}
