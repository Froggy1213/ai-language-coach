<?php

namespace App\Mistakes;

use App\Models\GrammarPoint;
use App\Models\Mistake;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Detects recurring grammatical errors across distinct voice sessions (plan §3, §7).
 *
 * Implements the plan §3 query:
 *   SELECT grammar_point_id, COUNT(DISTINCT session_id) AS session_count
 *   FROM mistakes
 *   WHERE user_id = ? AND created_at >= NOW() - INTERVAL 7 DAY
 *   GROUP BY grammar_point_id
 *   HAVING session_count >= 3;
 *
 * Judgement calls & design choices:
 * 1. Window boundary: We use an inclusive lower bound `created_at >= $windowStart`
 *    matching plan §3 SQL. A mistake timestamped exactly at ($now - 7 days) is
 *    included, whereas one second prior is excluded.
 * 2. Session statuses: Mistakes from sessions in any status (completed, abandoned,
 *    or failed) count. If a learner made a grammatical error in an abandoned or failed
 *    session, that error still reflects an authentic spoken struggle that warrants review.
 * 3. N+1 prevention: Grammar points for all detected ids are loaded in a single `whereIn` query.
 * 4. Deterministic sorting: Results are ordered by `sessionCount` descending, then
 *    `lastMistakeAt` descending.
 */
final class RecurringMistakeDetector
{
    public function __construct(
        private readonly ?int $windowDays = null,
        private readonly ?int $threshold = null,
    ) {}

    /**
     * @return Collection<int, RecurringMistake>
     */
    public function detect(User $user, ?CarbonInterface $now = null): Collection
    {
        $windowDays = $this->windowDays ?? (int) config('review.recurring_window_days', 7);
        $threshold = $this->threshold ?? (int) config('review.recurring_session_threshold', 3);

        $referenceTime = $now !== null
            ? CarbonImmutable::instance($now)->setTimezone('UTC')
            : CarbonImmutable::now('UTC');

        $windowStart = $referenceTime->subDays($windowDays);

        $results = Mistake::query()
            ->select([
                'grammar_point_id',
                DB::raw('COUNT(DISTINCT session_id) AS session_count'),
                DB::raw('COUNT(*) AS mistake_count'),
                DB::raw('MAX(created_at) AS last_mistake_at'),
            ])
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', $windowStart)
            ->groupBy('grammar_point_id')
            ->havingRaw('COUNT(DISTINCT session_id) >= ?', [$threshold])
            ->orderByDesc('session_count')
            ->orderByDesc('last_mistake_at')
            ->get();

        if ($results->isEmpty()) {
            return collect();
        }

        $grammarPoints = GrammarPoint::query()
            ->whereIn('id', $results->pluck('grammar_point_id'))
            ->get()
            ->keyBy('id');

        /** @var Collection<int, RecurringMistake> $recurring */
        $recurring = $results->map(function ($row) use ($grammarPoints): ?RecurringMistake {
            $grammarPointId = (int) $row->grammar_point_id;
            $grammarPoint = $grammarPoints->get($grammarPointId);

            if (! $grammarPoint) {
                return null;
            }

            return new RecurringMistake(
                grammarPointId: $grammarPointId,
                grammarPoint: $grammarPoint,
                sessionCount: (int) $row->session_count,
                mistakeCount: (int) $row->mistake_count,
                lastMistakeAt: CarbonImmutable::parse($row->last_mistake_at, 'UTC'),
            );
        })->filter()->values();

        return $recurring->sort(function (RecurringMistake $a, RecurringMistake $b): int {
            if ($b->sessionCount !== $a->sessionCount) {
                return $b->sessionCount <=> $a->sessionCount;
            }

            return $b->lastMistakeAt <=> $a->lastMistakeAt;
        })->values();
    }
}
