<?php

namespace App\Assessments;

use App\Enums\AssessmentStatus;
use App\Grammar\RoadmapGenerator;
use App\GraphQL\Subscriptions\AssessmentReady;
use App\Models\Assessment;
use App\Models\User;
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
        PronunciationAnalyzer $pronunciation,
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

        if (! $user instanceof User) {
            return;
        }

        // The object is checked again here, not only when the upload was
        // submitted: it may have been replaced or removed since, and the
        // transcription cannot work from a URL the bucket will not serve.
        $upload = $audio->inspect($user, $assessment->audio_url);

        // The staged copy is removed by `transcribe()` on success and on failure
        // alike; the `finally` covers a failure before it is even called, so a
        // recording never outlives the job that staged it.
        $recording = $audio->fetch($upload);

        try {
            $transcription = $transcriber->transcribe($recording, $user->target_language);
        } finally {
            $recording->discard();
        }

        // The clarity evidence is measured before the model is called: the
        // numbers are arithmetic over the provider payload, so revising the
        // prompt, the model or the provider must not change what a recording
        // scored. The model's only contribution is the sentence about it.
        $clarity = $pronunciation->analyze($transcription->features);

        $result = $assessor->assess($transcription, $user, $clarity, $pronunciation);

        // The measurement is this job's, and the model contributes only the
        // sentence about it. An assessor that returns without attaching the block
        // — a stub, or a future implementation — must not cost the learner the
        // evidence that was already computed for their recording.
        $result = $result->withPronunciation(
            ($result->pronunciation ?? $clarity->withAdvice(null)),
        );

        DB::transaction(function () use ($assessment, $user, $transcription, $result, $roadmaps): void {
            $user->forceFill(['current_level' => $result->level])->save();

            // The roadmap follows the level the analysis settled on. Regenerating
            // archives the previous plan rather than deleting it.
            $roadmaps->regenerate($user->refresh());

            $assessment->update([
                'status' => AssessmentStatus::Done,
                'cefr_level' => $result->level,
                'raw_data' => array_merge($assessment->raw_data ?? [], [
                    'transcript' => $transcription->text,
                    'duration_seconds' => $transcription->durationSeconds,
                    // Kept next to the transcript so a level that looks wrong
                    // later can be explained from the evidence it was given.
                    'speech_features' => $transcription->features->toPromptArray(),
                    'analysis' => $result->toArray(),
                    'pronunciation' => $result->pronunciation?->toArray(),
                ]),
            ]);
        });

        // Fresh state on the broadcast: the pronunciation block was written in
        // the transaction above, and a subscriber holding the instance the job
        // started with would read a `raw_data` from before it existed. The
        // failure path reloads for the same reason.
        $assessment->refresh();

        // Privacy (plan §5): the recording is kept no longer than the STT pass
        // needs it. Deliberately last — a failure above must leave the audio in
        // place so a retry can transcribe it again.
        $audio->delete($assessment->audio_url);

        // The result is already stored, so a broadcast that cannot be delivered
        // must not fail the job and throw the analysis away: Lighthouse restores
        // every subscriber of the topic before filtering, and one whose user no
        // longer exists takes the whole push down. The client can still see the
        // new level by asking again (its screen offers exactly that).
        try {
            $broadcasts->broadcast(new AssessmentReady, 'assessmentReady', $assessment);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Called once the attempts are exhausted. The waitlist screen would hang on
     * `processing` forever, so the failure is written where the client looks
     * and broadcast over the same subscription the success path uses.
     */
    public function failed(?Throwable $exception, ?BroadcastsSubscriptions $broadcasts = null): void
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

        try {
            $broadcasts ??= app(BroadcastsSubscriptions::class);
            $broadcasts->broadcast(new AssessmentReady, 'assessmentReady', $assessment);
        } catch (Throwable $broadcastException) {
            report($broadcastException);
        }
    }
}
