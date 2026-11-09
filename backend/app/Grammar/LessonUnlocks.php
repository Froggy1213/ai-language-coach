<?php

namespace App\Grammar;

use App\Enums\CefrLevel;
use App\Enums\LessonCardStatus;
use App\Models\GrammarPoint;
use App\Models\LessonCard;
use App\Models\Mistake;
use App\Models\Roadmap;
use Illuminate\Database\Eloquent\Collection;

/**
 * Computes and synchronises lesson card unlock states across the grammar DAG.
 *
 * A card is unlocked (`ready`) when either:
 * 1. All of its prerequisite grammar points in the roadmap are `completed`, or
 * 2. The learner has recorded a mistake on that grammar point in a voice session
 *    (adaptive unlocking: if a learner is already attempting the structure in
 *    live speech and getting it wrong, the lesson should be open immediately).
 *
 * Unlocking is monotonic: `locked -> ready -> completed` never regresses.
 */
final class LessonUnlocks
{
    public function __construct(private readonly GrammarCatalogue $catalogue) {}

    /**
     * Whether every prerequisite of `$code` has been completed.
     *
     * @param  list<string>  $completedCodes
     * @param  array<string, list<string>>  $prerequisitesByCode
     */
    public static function isUnlocked(string $code, array $completedCodes, array $prerequisitesByCode): bool
    {
        $completedLookup = array_fill_keys($completedCodes, true);

        foreach ($prerequisitesByCode[$code] ?? [] as $prerequisiteCode) {
            if (! isset($completedLookup[$prerequisiteCode])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compute the initial card status for every entry in a newly generated roadmap.
     *
     * Points below the learner's assessed CEFR band are pre-completed (`completed`),
     * and any point at or above their band whose prerequisites are satisfied (or
     * on which the learner has already made mistakes) starts as `ready`.
     *
     * @param  list<GrammarPointContent>  $contents
     * @param  list<string>  $mistakeCodes
     * @return array<string, LessonCardStatus>
     */
    public function initialStatuses(
        string $language,
        array $contents,
        CefrLevel $learnerLevel,
        array $mistakeCodes = [],
    ): array {
        $prerequisitesByCode = $this->prerequisiteMap($language);
        $mistakeLookup = array_fill_keys($mistakeCodes, true);
        $completedCodes = [];
        $statuses = [];

        foreach ($contents as $content) {
            if ($content->level->rank() < $learnerLevel->rank()) {
                $statuses[$content->code] = LessonCardStatus::Completed;
                $completedCodes[] = $content->code;
            }
        }

        foreach ($contents as $content) {
            if (isset($statuses[$content->code])) {
                continue;
            }

            $unlockedByGraph = self::isUnlocked($content->code, $completedCodes, $prerequisitesByCode);
            $unlockedByMistake = isset($mistakeLookup[$content->code]);

            $statuses[$content->code] = ($unlockedByGraph || $unlockedByMistake)
                ? LessonCardStatus::Ready
                : LessonCardStatus::Locked;
        }

        return $statuses;
    }

    /**
     * Promote any `locked` cards in `$roadmap` whose prerequisites are now
     * satisfied or whose grammar point IDs are in `$forceReadyGrammarPointIds`.
     *
     * @param  list<int>  $forceReadyGrammarPointIds
     * @return int Number of cards promoted from `locked` to `ready`.
     */
    public function syncRoadmap(
        Roadmap $roadmap,
        array $forceReadyGrammarPointIds = [],
        ?LessonCard $completedCard = null,
        bool $dryRun = false,
    ): int {
        /** @var Collection<int, LessonCard> $cards */
        $cards = $roadmap->lessonCards()
            ->with('grammarPoint.prerequisites')
            ->orderBy('order_index')
            ->lockForUpdate()
            ->get();

        if ($cards->isEmpty()) {
            return 0;
        }

        $language = $roadmap->user?->target_language
            ?? $cards->first()?->grammarPoint?->language
            ?? 'en';

        $cataloguePrereqs = $this->prerequisiteMap($language);
        $forceReadyLookup = array_fill_keys($forceReadyGrammarPointIds, true);

        $completedCodes = [];
        $completedOrderIndexes = [];
        $usesDag = false;

        foreach ($cards as $card) {
            $point = $card->grammarPoint;

            if ($point !== null) {
                if (array_key_exists($point->code, $cataloguePrereqs) || $point->prerequisites->isNotEmpty()) {
                    $usesDag = true;
                }
            }

            if ($card->status === LessonCardStatus::Completed) {
                if ($point !== null) {
                    $completedCodes[] = $point->code;
                }
                $completedOrderIndexes[$card->order_index] = true;
            }
        }

        if ($completedCard !== null) {
            $completedOrderIndexes[$completedCard->order_index] = true;
        }

        $prerequisitesByCode = [];
        if ($usesDag) {
            foreach ($cards as $card) {
                $point = $card->grammarPoint;
                if ($point === null) {
                    continue;
                }

                $dbPrereqs = $point->prerequisites
                    ->pluck('code')
                    ->values()
                    ->all();

                $catPrereqs = $cataloguePrereqs[$point->code] ?? [];

                $prerequisitesByCode[$point->code] = array_values(array_unique([
                    ...$catPrereqs,
                    ...$dbPrereqs,
                ]));
            }
        }

        $unlockedCount = 0;

        foreach ($cards as $card) {
            if ($card->status !== LessonCardStatus::Locked) {
                continue;
            }

            $point = $card->grammarPoint;
            $shouldUnlock = isset($forceReadyLookup[$card->grammar_point_id]);

            if (! $shouldUnlock) {
                if ($usesDag && $point !== null) {
                    $shouldUnlock = self::isUnlocked($point->code, $completedCodes, $prerequisitesByCode);
                } else {
                    $shouldUnlock = $completedCard !== null
                        ? $card->order_index === $completedCard->order_index + 1
                        : isset($completedOrderIndexes[$card->order_index - 1]);
                }
            }

            if ($shouldUnlock) {
                if (! $dryRun) {
                    $card->update(['status' => LessonCardStatus::Ready]);
                }
                $unlockedCount++;
            }
        }

        return $unlockedCount;
    }

    /**
     * Grammar point IDs for which the user has recorded mistakes (excluding `uncategorized`).
     *
     * @return list<int>
     */
    public function mistakeGrammarPointIdsForUser(int $userId, ?string $language = null): array
    {
        return Mistake::query()
            ->where('mistakes.user_id', $userId)
            ->join('grammar_points', 'mistakes.grammar_point_id', '=', 'grammar_points.id')
            ->when(
                $language !== null,
                static fn ($query) => $query->where('grammar_points.language', $language),
            )
            ->where('grammar_points.code', '!=', GrammarPoint::UNCATEGORIZED_CODE)
            ->distinct()
            ->pluck('mistakes.grammar_point_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Grammar point codes for which the user has recorded mistakes (excluding `uncategorized`).
     *
     * @return list<string>
     */
    public function mistakeCodesForUser(int $userId, string $language): array
    {
        /** @var list<string> $codes */
        $codes = Mistake::query()
            ->where('mistakes.user_id', $userId)
            ->join('grammar_points', 'mistakes.grammar_point_id', '=', 'grammar_points.id')
            ->where('grammar_points.language', $language)
            ->where('grammar_points.code', '!=', GrammarPoint::UNCATEGORIZED_CODE)
            ->distinct()
            ->pluck('grammar_points.code')
            ->values()
            ->all();

        return $codes;
    }

    /**
     * @return array<string, list<string>>
     */
    private function prerequisiteMap(string $language): array
    {
        if (method_exists($this->catalogue, 'prerequisiteMap')) {
            return $this->catalogue->prerequisiteMap($language);
        }

        $map = [];

        foreach ($this->catalogue->forLanguage($language) as $content) {
            $map[$content->code] = $content->prerequisites ?? [];
        }

        return $map;
    }
}
