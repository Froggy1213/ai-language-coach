<?php

namespace App\Assessments;

/**
 * What the speech-to-text pass produced for one recording.
 */
final readonly class Transcription
{
    /**
     * @param  array<string, mixed>  $raw  Provider response, kept for `assessments.raw_data`.
     * @param  SpeechFeatures  $features  Fluency evidence read out of the word timings.
     */
    public function __construct(
        public string $text,
        public float $durationSeconds,
        public array $raw,
        public SpeechFeatures $features = new SpeechFeatures(wordCount: 0),
    ) {}
}
