<?php

namespace App\Mistakes;

use App\GraphQL\Subscriptions\SessionFeedbackReady;
use App\Models\Mistake;
use App\Models\ReviewItem;
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
 * `review_items` rows with SM-2 defaults, and fires the `sessionFeedbackReady` subscription.
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
    ): void {
        $session = $this->session->fresh();

        if (! $session instanceof VoiceSession || ! $session->status->isTerminal()) {
            return;
        }

        // Idempotency guard: skip if this session already has analysed mistakes or was marked analysed.
        if ($session->mistakes()->exists() || $this->alreadyAnalyzed($session)) {
            return;
        }

        $utterances = $this->extractLearnerUtterances($session);
        if (empty($utterances)) {
            return;
        }

        $transcriptText = implode("\n", $utterances);
        $result = $analyzer->analyze($transcriptText, $session->user);

        DB::transaction(function () use ($session, $result): void {
            $lockedSession = VoiceSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedSession instanceof VoiceSession) {
                return;
            }

            if ($lockedSession->mistakes()->exists() || $this->alreadyAnalyzed($lockedSession)) {
                return;
            }

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
            }

            $this->markAnalyzed($lockedSession, count($result->mistakes));
        });

        try {
            $broadcasts->broadcast(new SessionFeedbackReady, 'sessionFeedbackReady', $session->fresh());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return list<string>
     */
    private function extractLearnerUtterances(VoiceSession $session): array
    {
        $transcript = $session->transcript;
        if (! is_array($transcript)) {
            return [];
        }

        $utterances = [];
        foreach ($transcript as $turn) {
            if (! is_array($turn) || ($turn['type'] ?? null) === 'analysis') {
                continue;
            }

            $text = trim((string) ($turn['transcript'] ?? ''));
            if ($text !== '') {
                $utterances[] = $text;
            }
        }

        return $utterances;
    }

    private function alreadyAnalyzed(VoiceSession $session): bool
    {
        $transcript = $session->transcript;
        if (! is_array($transcript)) {
            return false;
        }

        foreach ($transcript as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'analysis') {
                return true;
            }
        }

        return false;
    }

    private function markAnalyzed(VoiceSession $session, int $mistakesCount): void
    {
        $transcript = $session->transcript ?? [];
        if (! is_array($transcript)) {
            $transcript = [];
        }

        $transcript[] = [
            'type' => 'analysis',
            'analyzed_at' => Carbon::now()->toIso8601String(),
            'mistakes_count' => $mistakesCount,
        ];

        $session->transcript = array_values($transcript);
        $session->save();
    }
}
