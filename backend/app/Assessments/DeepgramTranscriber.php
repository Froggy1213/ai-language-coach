<?php

namespace App\Assessments;

use DIJ\Deepgram\Deepgram;

/**
 * Batch speech-to-text for an uploaded recording (plan §5).
 *
 * Deepgram fetches the object from S3 itself — the audio never passes through
 * this process — so the assessment job only needs the presigned-free object URL
 * that was stored when the upload was submitted.
 *
 * The `dij-digital/deepgram-laravel` package wraps the request; its own model
 * and language defaults come from `config/deepgram-laravel.php` (Nova-2,
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
    public function transcribe(string $audioUrl, ?string $language = null): Transcription
    {
        $response = $this->deepgram->listen()->transcribeUrl($audioUrl, array_filter([
            'language' => $language,
            'smart_format' => true,
            'punctuate' => true,
        ]));

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
}
