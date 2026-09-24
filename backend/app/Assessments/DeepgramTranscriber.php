<?php

namespace App\Assessments;

use DIJ\Deepgram\Deepgram;

/**
 * Batch speech-to-text for an uploaded recording (plan §5).
 *
 * The audio is posted to Deepgram as a file body rather than as an S3 URL. The
 * bucket is private, so a URL would come back 403 to the provider — and a
 * presigned one would hand the recording to a third party for as long as it
 * lives. Sending the bytes also means a local S3-compatible store behaves
 * exactly like the real bucket.
 *
 * The `dij-digital/deepgram-laravel` package wraps the request; its model and
 * language defaults come from `config/deepgram-laravel.php` (Nova-2,
 * `DEEPGRAM_DEFAULT_LANGUAGE`). Response time is governed by the global HTTP
 * options set in AppServiceProvider: a long recording takes longer than the
 * framework's 30-second default.
 */
final class DeepgramTranscriber
{
    public function __construct(private readonly Deepgram $deepgram) {}

    /**
     * @throws TranscriptionFailed when the provider returns no usable text.
     */
    public function transcribe(Recording $recording, ?string $language = null): Transcription
    {
        $path = $this->spool($recording);

        try {
            $response = $this->deepgram->listen()->transcribeFile(
                absoluteFilePath: $path,
                mimeType: $recording->contentType,
                options: array_filter([
                    'language' => $language,
                    'smart_format' => true,
                    'punctuate' => true,
                ]),
            );
        } finally {
            // The package has read the file by now; a recording should not
            // outlive the call that needed it (plan §5).
            @unlink($path);
        }

        $text = trim((string) data_get($response, 'results.channels.0.alternatives.0.transcript', ''));

        if ($text === '') {
            // An empty transcript means silence or noise: there is nothing to
            // assess, and sending it to the LLM would buy a meaningless level.
            throw new TranscriptionFailed('Deepgram returned an empty transcript for the recording.');
        }

        return new Transcription(
            text: $text,
            durationSeconds: (float) data_get($response, 'metadata.duration', 0),
            raw: $response,
        );
    }

    /**
     * The package takes a path, not a stream, so the bytes are spooled to a
     * temporary file for the duration of the call.
     */
    private function spool(Recording $recording): string
    {
        $path = tempnam(sys_get_temp_dir(), 'assessment-recording-');

        if ($path === false || file_put_contents($path, $recording->contents) === false) {
            throw new TranscriptionFailed('The recording could not be spooled to a temporary file.');
        }

        return $path;
    }
}
