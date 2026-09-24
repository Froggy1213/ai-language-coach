<?php

namespace App\Assessments;

use App\Models\User;

/**
 * Boundary to the bucket that holds onboarding audio (plan §5).
 *
 * The S3 implementation is the only class that knows about buckets, keys and
 * signatures; the mutations and the analysis job talk to this interface, which
 * is what lets the pipeline be tested without touching AWS.
 */
interface AssessmentAudioStorage
{
    /**
     * Authorise one upload for this learner, with the size and content-type
     * limits baked into the signature.
     */
    public function presignUpload(User $user, string $contentType): PresignedUpload;

    /**
     * Look up an uploaded object and check it against the limits again.
     *
     * @throws InvalidAssessmentAudio when the URL is not this learner's upload,
     *                                the object is missing, or it breaks a limit.
     */
    public function inspect(User $user, string $fileUrl): AudioUpload;

    /**
     * Read the object back out of the bucket.
     *
     * The transcription provider needs the audio itself: the bucket is private,
     * so handing out a URL would not survive the first request.
     *
     * @throws InvalidAssessmentAudio when the object is missing or unreadable.
     */
    public function fetch(AudioUpload $upload): Recording;

    /**
     * Drop the raw audio once it has been transcribed (plan §5: recordings are
     * kept no longer than the STT pass needs them).
     */
    public function delete(string $fileUrl): void;
}
