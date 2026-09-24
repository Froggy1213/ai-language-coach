<?php

namespace App\Assessments;

use App\Models\User;
use Aws\S3\Exception\S3Exception;
use Aws\S3\PostObjectV4;
use Aws\S3\S3Client;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * S3 implementation of the assessment audio boundary (plan §5).
 *
 * Uploads are presigned POSTs, not PUTs: only the POST policy can carry the
 * `content-length-range` and `Content-Type` conditions, so S3 itself rejects an
 * oversized or mislabelled recording before it is stored. `inspect()` repeats
 * the checks against the object that actually landed in the bucket, because the
 * client is free to upload something else after receiving the signature.
 */
final class S3AssessmentAudioStorage implements AssessmentAudioStorage
{
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
    ) {}

    public function presignUpload(User $user, string $contentType): PresignedUpload
    {
        $contentType = AudioContentType::normalize($contentType);
        $key = $this->keyFor($user, $contentType);

        $post = new PostObjectV4(
            client: $this->client,
            bucket: $this->bucket(),
            formInputs: [
                'key' => $key,
                'Content-Type' => $contentType,
            ],
            options: [
                // Every form field has to be covered by the policy, the bucket
                // included — without this condition S3 answers AccessDenied
                // ("Bucket not specified in the policy") and MinIO says so
                // explicitly.
                ['eq', '$bucket', $this->bucket()],
                ['content-length-range', 1, $this->maxSizeBytes()],
                ['eq', '$key', $key],
                ['eq', '$Content-Type', $contentType],
            ],
            expiration: now()->addMinutes((int) config('assessments.upload_url_ttl_minutes', 10)),
        );

        $attributes = $post->getFormAttributes();

        return new PresignedUpload(
            uploadUrl: $attributes['action'],
            fileUrl: $this->client->getObjectUrl($this->bucket(), $key),
            fields: $post->getFormInputs(),
        );
    }

    public function inspect(User $user, string $fileUrl): AudioUpload
    {
        $key = $this->keyFromUrl($fileUrl);

        if (! str_starts_with($key, $this->userPrefix($user))) {
            throw new InvalidAssessmentAudio('That upload does not belong to this learner.');
        }

        try {
            $head = $this->client->headObject([
                'Bucket' => $this->bucket(),
                'Key' => $key,
            ]);
        } catch (S3Exception $exception) {
            throw new InvalidAssessmentAudio('The uploaded audio could not be found in the bucket.', previous: $exception);
        }

        $size = (int) ($head['ContentLength'] ?? 0);
        $contentType = AudioContentType::normalize((string) ($head['ContentType'] ?? ''));

        if ($size <= 0) {
            throw new InvalidAssessmentAudio('The uploaded audio is empty.');
        }

        if ($size > $this->maxSizeBytes()) {
            throw new InvalidAssessmentAudio('The uploaded audio is larger than '.$this->maxSizeMegabytes().' MB.');
        }

        if (! AudioContentType::isAllowed($contentType)) {
            throw new InvalidAssessmentAudio("The uploaded audio has content type `{$contentType}`, which cannot be transcribed.");
        }

        return new AudioUpload(
            key: $key,
            fileUrl: $fileUrl,
            sizeBytes: $size,
            contentType: $contentType,
        );
    }

    public function fetch(AudioUpload $upload): Recording
    {
        try {
            $object = $this->client->getObject([
                'Bucket' => $this->bucket(),
                'Key' => $upload->key,
            ]);
        } catch (S3Exception $exception) {
            throw new InvalidAssessmentAudio('The uploaded audio could not be read back from the bucket.', previous: $exception);
        }

        return new Recording(
            contents: (string) $object['Body'],
            contentType: $upload->contentType,
        );
    }

    public function delete(string $fileUrl): void
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket(),
                'Key' => $this->keyFromUrl($fileUrl),
            ]);
        } catch (S3Exception $exception) {
            // Retention is best-effort here: the assessment is already stored,
            // and a failed delete must not throw the analysis away. Sentry and
            // the log still see it, and the bucket lifecycle policy is the net.
            report($exception);
        }
    }

    private function keyFor(User $user, string $contentType): string
    {
        return $this->userPrefix($user).Str::ulid().'.'.AudioContentType::extension($contentType);
    }

    /**
     * Reading the bucket lazily keeps a missing AWS_BUCKET from turning into a
     * confusing signature error: the failure names the setting instead.
     */
    private function bucket(): string
    {
        if ($this->bucket === '') {
            throw new RuntimeException('No S3 bucket is configured for assessment audio; set AWS_BUCKET.');
        }

        return $this->bucket;
    }

    private function userPrefix(User $user): string
    {
        return config('assessments.key_prefix', 'assessments').'/'.$user->getKey().'/';
    }

    /**
     * The key is read back out of the URL the client returns, but it is only
     * ever used against our own bucket — the host the client claims is ignored.
     */
    private function keyFromUrl(string $fileUrl): string
    {
        $path = parse_url($fileUrl, PHP_URL_PATH);

        if (! is_string($path)) {
            throw new InvalidAssessmentAudio('That is not an assessment upload URL.');
        }

        $key = ltrim(rawurldecode($path), '/');

        // A path-style endpoint (MinIO, or AWS with AWS_USE_PATH_STYLE_ENDPOINT)
        // carries the bucket as the first path segment; a virtual-host URL keeps
        // it in the host, so only strip it when it is really there.
        $bucketPrefix = $this->bucket().'/';

        if (str_starts_with($key, $bucketPrefix)) {
            $key = substr($key, strlen($bucketPrefix));
        }

        $prefix = config('assessments.key_prefix', 'assessments').'/';

        if (! str_starts_with($key, $prefix) || str_contains($key, '..')) {
            throw new InvalidAssessmentAudio('That is not an assessment upload URL.');
        }

        return $key;
    }

    private function maxSizeBytes(): int
    {
        return (int) config('assessments.max_size_bytes');
    }

    private function maxSizeMegabytes(): int
    {
        return (int) round($this->maxSizeBytes() / 1024 / 1024);
    }
}
