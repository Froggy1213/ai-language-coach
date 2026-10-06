<?php

namespace App\GraphQL\Mutations;

use App\Assessments\AnalyzeAssessment;
use App\Assessments\AssessmentAudioStorage;
use App\Assessments\InvalidAssessmentAudio;
use App\Enums\AssessmentStatus;
use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class SubmitAssessment
{
    public function __construct(private readonly AssessmentAudioStorage $audio) {}

    /**
     * @param  array{audioUrl: string}  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Assessment
    {
        $user = $context->user();
        assert($user instanceof User);

        try {
            // Checks the object the bucket really holds — its owner, size and
            // content type — before the pipeline spends anything on it.
            $upload = $this->audio->inspect($user, $args['audioUrl']);
        } catch (InvalidAssessmentAudio $exception) {
            throw ValidationException::withMessages(['audioUrl' => $exception->getMessage()]);
        }

        [$assessment, $shouldDispatch] = DB::transaction(function () use ($user, $upload): array {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $existing = $user->assessments()
                ->where('audio_url', $upload->fileUrl)
                ->latest('id')
                ->first();

            if ($existing instanceof Assessment) {
                return [$existing, false];
            }

            $assessment = $user->assessments()->create([
                'status' => AssessmentStatus::Processing,
                'audio_url' => $upload->fileUrl,
                'raw_data' => [
                    'upload' => [
                        'key' => $upload->key,
                        'size_bytes' => $upload->sizeBytes,
                        'content_type' => $upload->contentType,
                    ],
                ],
            ]);

            return [$assessment, true];
        });

        if ($shouldDispatch) {
            // The client learns the outcome through the `assessmentReady`
            // subscription, not by waiting on this request.
            AnalyzeAssessment::dispatch($assessment);
        }

        return $assessment;
    }
}
