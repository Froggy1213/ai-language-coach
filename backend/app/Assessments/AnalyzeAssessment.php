<?php

namespace App\Assessments;

use App\Enums\AssessmentStatus;
use App\Grammar\RoadmapGenerator;
use App\GraphQL\Subscriptions\AssessmentReady;
use App\Models\Assessment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Subscriptions\Contracts\BroadcastsSubscriptions;
use Throwable;

/**
 * The async half of the onboarding assessment (plan §5): transcribe the
 * recording, judge it against the CEFR bands, then move the learner onto the
 * roadmap their new level calls for.
 *
 * Two external services are called, so the job is retried with a backoff and
 * runs with a timeout well above the default worker one.
 */
final class AnalyzeAssessment implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    /**
     * Transcription plus analysis of a full-length recording. Must stay below
     * the queue connection's `retry_after`, or a second worker picks the job up
     * while this one is still running.
     */
    public int $timeout = 300;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly Assessment $assessment) {}

    public function handle(
        DeepgramTranscriber $transcriber,
        CefrAssessor $assessor,
        AssessmentAudioStorage $audio,
        RoadmapGenerator $roadmaps,
        BroadcastsSubscriptions $broadcasts,
    ): void {
        $assessment = $this->assessment->fresh();

        // A retry after a partial run, or a duplicate delivery, must not pay for
        // the transcription and the analysis a second time.
        if (! $assessment instanceof Assessment || $assessment->status !== AssessmentStatus::Processing) {
            return;
        }

        $user = $assessment->user;

        // The object is checked again here, not only when the upload was
        // submitted: it may have been replaced or removed since, and the
        // transcription cannot work from a URL the bucket will not serve.
        $upload = $audio->inspect($user, $assessment->audio_url);
        $recording = $audio->fetch($upload);

        $transcription = $transcriber->transcribe($recording, $user->target_language);
        $result = $assessor->assess($transcription, $user);

        DB::transaction(function () use ($assessment, $user, $transcription, $result): void {
            $assessment->update([
                'status' => AssessmentStatus::Done,
                'cefr_level' => $result->level,
                'raw_data' => array_merge($assessment->raw_data ?? [], [
                    'transcript' => $transcription->text,
                    'duration_seconds' => $transcription->durationSeconds,
                    'analysis' => $result->toArray(),
                ]),
            ]);

            $user->update(['current_level' => $result->level]);
        });

        // The roadmap follows the level the analysis settled on. Regenerating
        // archives the previous plan rather than deleting it.
        $roadmaps->regenerate($user->refresh());

        // Privacy (plan §5): the recording is kept no longer than the STT pass
        // needs it. Deliberately last — a failure above must leave the audio in
        // place so a retry can transcribe it again.
        $audio->delete($assessment->audio_url);

        $broadcasts->broadcast(new AssessmentReady, 'assessmentReady', $assessment);
    }

    /**
     * Called once the attempts are exhausted. The waitlist screen would hang on
     * `processing` forever, so the failure is written where the client looks.
     */
    public function failed(?Throwable $exception): void
    {
        $assessment = $this->assessment->fresh();

        if (! $assessment instanceof Assessment || $assessment->status !== AssessmentStatus::Processing) {
            return;
        }

        $assessment->update([
            'status' => AssessmentStatus::Failed,
            'raw_data' => array_merge($assessment->raw_data ?? [], [
                'error' => $exception?->getMessage(),
            ]),
        ]);
    }
}
