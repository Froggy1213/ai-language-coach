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
 * The `dij-digital/deepgram-laravel` package wraps the request. Its model and
 * language defaults come from `config/deepgram-laravel.php`, where the model is
 * `DEEPGRAM_DEFAULT_MODEL` (nova-3): passing a model here as well would leave
 * two places to change when the model is next revised. Response time is governed
 * by the global HTTP options set in AppServiceProvider — a long recording takes
 * longer than the framework's 30-second default.
 *
 * What comes back is kept whole: the analysis reads word timings and per-word
 * confidence out of it (see SpeechFeatures), not only the transcript string.
 */
final class DeepgramTranscriber
{
    public function __construct(private readonly Deepgram $deepgram) {}

    /**
     * @throws TranscriptionFailed when the provider returns no usable text.
     */
    public function transcribe(Recording $recording, ?string $language = null): Transcription
    {
        $recording->assertReadable();

        try {
            $response = $this->deepgram->listen()->transcribeFile(
                absoluteFilePath: $recording->path,
                mimeType: $recording->contentType,
                options: array_filter([
                    'language' => $language,
                    'smart_format' => true,
                    'punctuate' => true,
                    // Hesitations are the point: a CEFR examiner reads "I, um,
                    // went" differently from "I went", and the filler count in
                    // SpeechFeatures is empty without this.
                    'filler_words' => true,
                ]),
            );
        } finally {
            // The staged copy is consumed by this call: the provider has the
            // bytes by now, and rendering them into a transcription is not the
            // same thing as keeping the audio (plan §5). The caller keeps a
            // defensive `discard()` for a failure before this point, and
            // `discard()` is idempotent, so removing it twice is free.
            $recording->discard();
        }

        $text = trim((string) data_get($response, 'results.channels.0.alternatives.0.transcript', ''));

        if ($text === '') {
            // An empty transcript means silence or noise: there is nothing to
            // assess, and sending it to the LLM would buy a meaningless level.
            throw new TranscriptionFailed('Deepgram returned an empty transcript for the recording.');
        }

        $durationSeconds = (float) data_get($response, 'metadata.duration', 0);

        return new Transcription(
            text: $text,
            durationSeconds: $durationSeconds,
            raw: $response,
            features: SpeechFeatures::fromDeepgramResponse($response, $durationSeconds),
        );
    }
}
