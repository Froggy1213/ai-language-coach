<?php

namespace App\Mistakes;

use App\Enums\RoadmapStatus;
use App\Grammar\LessonUnlocks;
use App\GraphQL\Subscriptions\SessionFeedbackReady;
use App\Models\GrammarPoint;
use App\Models\Mistake;
use App\Models\ReviewItem;
use App\Models\Roadmap;
use App\Models\VoiceSession;
use App\Review\Sm2;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Subscriptions\Contracts\BroadcastsSubscriptions;
use Throwable;

/**
 * The async mistake analysis of a voice session (plan §5).
 *
 * Takes the session's accumulated turns, builds a transcript of the learner's
 * utterances, asks the LLM for a structured list of grammatical mistakes constrained
 * to the canonical grammar catalogue, writes `mistakes` rows, lazily creates
 * `review_items` rows with SM-2 defaults, unlocks any locked lesson cards for
 * the grammar points the learner struggled with, and fires the
 * `sessionFeedbackReady` subscription.
 *
 * For a scenario session it then asks a second, separate model to judge whether
 * the learner achieved the scenario's goal (plan §9) and stores that verdict in
 * `goal_review`. A failed review is reported and skipped: the grammar mistakes
 * and the broadcast do not depend on it.
 *
 * Idempotent: a re-run of the job for an already analysed session skips processing
 * to avoid duplicate mistakes or overwriting SM-2 schedules.
 */
final class AnalyzeVoiceSessionMistakes implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly VoiceSession $session) {}

    public function handle(
        MistakeAnalyzer $analyzer,
        BroadcastsSubscriptions $broadcasts,
        ?GoalReviewer $goalReviewer = null,
    ): void {
        $session = $this->session->fresh();

        if (! $session instanceof VoiceSession || ! $session->status->isTerminal()) {
            return;
        }

        // Idempotency guard: skip if this session already has analysed mistakes or was marked analysed.
        if ($session->mistakes()->exists() || $session->hasAnalysisMarker()) {
            return;
        }

        $utterances = $session->learnerUtterances();
        if (empty($utterances)) {
            $shouldReturn = false;
            $shouldBroadcast = false;

            DB::transaction(function () use ($session, &$shouldReturn, &$shouldBroadcast, &$utterances): void {
                $lockedSession = VoiceSession::query()
                    ->whereKey($session->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedSession instanceof VoiceSession) {
                    $shouldReturn = true;

                    return;
                }

                if ($lockedSession->mistakes()->exists() || $lockedSession->hasAnalysisMarker()) {
                    $shouldReturn = true;

                    return;
                }

                $lockedUtterances = $lockedSession->learnerUtterances();
                if (empty($lockedUtterances)) {
                    $lockedSession->markAnalyzed(0);
                    $shouldReturn = true;
                    $shouldBroadcast = true;

                    return;
                }

                $utterances = $lockedUtterances;
            });

            if ($shouldReturn) {
                if ($shouldBroadcast) {
                    try {
                        $broadcasts->broadcast(new SessionFeedbackReady, 'sessionFeedbackReady', $session->fresh());
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }

                return;
            }
        }

        $transcriptText = implode("\n", $utterances);
        $result = $analyzer->analyze($transcriptText, $session->user);

        // Whether *this* run committed the analysis. A concurrent duplicate can
        // pass the pre-transaction guard and still lose the race under the row
        // lock; it must not run the goal review or broadcast a second time.
        $analysedNow = false;

        DB::transaction(function () use ($session, $result, &$analysedNow): void {
            $lockedSession = VoiceSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedSession instanceof VoiceSession) {
                return;
            }

            if ($lockedSession->mistakes()->exists() || $lockedSession->hasAnalysisMarker()) {
                return;
            }

            $mistakeGrammarPointIds = [];

            foreach ($result->mistakes as $mistake) {
                Mistake::create([
                    'session_id' => $lockedSession->id,
                    'user_id' => $lockedSession->user_id,
                    'grammar_point_id' => $mistake->grammarPoint->id,
                    'user_utterance' => $mistake->userUtterance,
                    'correction' => $mistake->correction,
                    'explanation' => $mistake->explanation,
                ]);

                // Lazily initialize SM-2 review item with defaults if none exists (plan §5).
                ReviewItem::firstOrCreate(
                    [
                        'user_id' => $lockedSession->user_id,
                        'grammar_point_id' => $mistake->grammarPoint->id,
                    ],
                    [
                        'ease_factor' => Sm2::DEFAULT_EASE_FACTOR,
                        'interval_days' => Sm2::DEFAULT_INTERVAL_DAYS,
                        'repetition_number' => Sm2::DEFAULT_REPETITION_NUMBER,
                        'next_review_at' => Carbon::now()->addDays(Sm2::DEFAULT_INTERVAL_DAYS),
                    ],
                );

                if ($mistake->grammarPoint->code !== GrammarPoint::UNCATEGORIZED_CODE) {
                    $mistakeGrammarPointIds[] = (int) $mistake->grammarPoint->id;
                }
            }

            if ($mistakeGrammarPointIds !== []) {
                $activeRoadmap = Roadmap::query()
                    ->where('user_id', $lockedSession->user_id)
                    ->where('status', RoadmapStatus::Active)
                    ->lockForUpdate()
                    ->first();

                if ($activeRoadmap instanceof Roadmap) {
                    app(LessonUnlocks::class)->syncRoadmap(
                        $activeRoadmap,
                        array_values(array_unique($mistakeGrammarPointIds)),
                    );
                }
            }

            $lockedSession->markAnalyzed(count($result->mistakes));
            $analysedNow = true;
        });

        if (! $analysedNow) {
            return;
        }

        $this->storeGoalReview($session, $goalReviewer);

        try {
            $broadcasts->broadcast(new SessionFeedbackReady, 'sessionFeedbackReady', $session->fresh());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Store the communication review of a scenario session (plan §9).
     *
     * Only scenario sessions are reviewed, and only once: a session that
     * already carries a review is left alone. The review is best-effort — a
     * failing model or an unknown scenario is reported and skipped, because
     * losing the grammar mistakes and the completion broadcast over a second
     * opinion would be worse than a null `goal_review`.
     */
    private function storeGoalReview(VoiceSession $session, ?GoalReviewer $goalReviewer): void
    {
        if ($session->scenario_code === null || $session->goal_review !== null) {
            return;
        }

        try {
            $goalReviewer ??= app(GoalReviewer::class);

            $reviewableSession = $session->fresh();

            if (! $reviewableSession instanceof VoiceSession || $reviewableSession->goal_review !== null) {
                return;
            }

            $review = $goalReviewer->review($reviewableSession);

            $reviewableSession->goal_review = [
                'goal_achieved' => $review->goalAchieved,
                'missed_expressions' => $review->missedExpressions,
            ];
            $reviewableSession->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Called once the attempts are exhausted. Reports the exception and broadcasts
     * sessionFeedbackReady so a waiting client does not hang.
     */
    public function failed(?Throwable $exception = null, ?BroadcastsSubscriptions $broadcasts = null): void
    {
        if ($exception !== null) {
            report($exception);
        }

        $session = $this->session->fresh();

        if (! $session instanceof VoiceSession) {
            return;
        }

        try {
            $broadcasts ??= app(BroadcastsSubscriptions::class);
            $broadcasts->broadcast(new SessionFeedbackReady, 'sessionFeedbackReady', $session);
        } catch (Throwable $broadcastException) {
            report($broadcastException);
        }
    }
}
