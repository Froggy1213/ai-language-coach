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
 * Builds the roadmap a learner's current CEFR band calls for (plan §5, §6).
 *
 * The plan runs this right after the assessment resolves a level, and the
 * `generateRoadmap` mutation exposes it for a user who has no roadmap yet, so
 * both entry points retry: generation is idempotent and returns the existing
 * active roadmap instead of building a second one.
 */
final class RoadmapGenerator
{
    public function __construct(private readonly GrammarCatalogue $catalogue) {}

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
        $contents = $this->catalogue->upTo($user->target_language, $user->current_level);

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

            $roadmap = Roadmap::query()->create([
                'user_id' => $user->getKey(),
                'title' => $this->title($user, $contents),
                'status' => RoadmapStatus::Active,
            ]);

            $roadmap->lessonCards()->createMany($this->cards($contents, $grammarPointIds));

            return $roadmap;
        });
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
     * The first card is the one to practise now; everything after it waits for
     * `completeLessonCard` to unlock it (plan §5).
     *
     * @param  list<GrammarPointContent>  $contents
     * @param  array<string, int>  $grammarPointIds
     * @return list<array<string, mixed>>
     */
    private function cards(array $contents, array $grammarPointIds): array
    {
        $cards = [];

        foreach ($contents as $index => $content) {
            $cards[] = [
                'grammar_point_id' => $grammarPointIds[$content->code],
                'order_index' => $index + 1,
                'status' => $index === 0 ? LessonCardStatus::Ready : LessonCardStatus::Locked,
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
        $language = config("languages.names.{$user->target_language}") ?? mb_strtoupper($user->target_language);

        // Contents are ordered weakest first, so the last one is the strongest
        // band the catalogue actually covers for this learner — which is not
        // necessarily their own band.
        $weakest = $contents[0]->level;
        $strongest = $contents[array_key_last($contents)]->level;

        return $weakest === $strongest
            ? "{$language} · {$weakest->value}"
            : "{$language} · {$weakest->value}–{$strongest->value}";
    }
}
