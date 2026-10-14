<?php

namespace App\Assessments;

use App\Enums\CefrLevel;
use App\Models\User;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\StructuredAgentResponse;

use function Laravel\Ai\agent;

/**
 * Judges a transcript on the CEFR scale with DeepSeek (plan §5 — async work,
 * where price beats latency).
 *
 * The SDK does not validate structured output: the schema is only appended to
 * the instructions and used to ask for a JSON object, and a reply that does not
 * decode silently becomes an empty array. Everything the model returns is
 * therefore validated here before it can reach `users.current_level`.
 */
final class DeepSeekCefrAssessor implements CefrAssessor
{
    public function assess(Transcription $transcription, User $user): AssessmentResult
    {
        $response = agent($this->instructions($user), schema: $this->schema())
            ->prompt(
                $this->prompt($transcription),
                [],
                (string) config('assessments.analysis.provider'),
                (string) config('assessments.analysis.model'),
                (int) config('assessments.analysis.timeout_seconds'),
            );

        if (! $response instanceof StructuredAgentResponse) {
            throw new AssessmentAnalysisFailed('The analysis agent returned an unstructured response.');
        }

        return $this->result($response->structured);
    }

    /**
     * The transcript, plus the measurements the words themselves cannot express.
     *
     * A batch transcription without word timings (a stubbed provider, a model
     * that does not return them) falls back to the bare transcript rather than
     * printing zeroes the caller never measured.
     */
    private function prompt(Transcription $transcription): string
    {
        if ($transcription->features->isEmpty()) {
            return $transcription->text;
        }

        // The measurements come from a provider response, so a malformed byte
        // sequence is possible. `json_encode` returns false rather than throwing,
        // and `false` interpolates to an empty string — which would hand the model
        // an empty block under a heading promising measurements. Encoding
        // failures fall back to the bare transcript instead.
        $measurements = json_encode(
            $transcription->features->toPromptArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if (! is_string($measurements) || $measurements === '') {
            return $transcription->text;
        }

        // Which caveat to add depends on what was actually measured: a response
        // with no word timings still carries real confidence figures, and the
        // prompt must not invite the model to reason about a fluency number it
        // was never given.
        $timings = $transcription->features->hasTimings()
            ? 'The figures above are what the recogniser measured; weigh them as described below.'
            : 'This transcription carries no word timings, so no fluency figure appears above. '
                .'The confidence figures are still evidence about the audio quality.';

        return <<<PROMPT
        Recording length: {$transcription->durationSeconds} seconds.

        Measurements taken from the transcription:
        {$measurements}

        {$timings}

        Use these as context, within these limits:
        - `words_per_minute`, `longest_pause_between_words_seconds` and
          `silence_share_of_recording` describe delivery. Flat, even delivery
          supports the band the grammar and vocabulary justify; hesitant delivery
          with long silences is worth naming in the feedback as something to work
          on. They must not move the band on their own — the band is a judgement
          of what the learner can do with the language, and someone who speaks
          slowly but accurately is not a lower level because of it.
        - `silence_share_of_recording` counts every moment without recognised
          words, including the silence before the learner started speaking, and
          the pauses between sentences. It is not evidence of confusion on its own.
        - `low_confidence_word_share` and `words_the_recogniser_was_unsure_about`
          are the weakest signal here, and the only one that is about the audio
          rather than the learner. A recogniser is unsure because of a poor
          microphone, background noise and an unfamiliar accent just as much as
          because of a word the learner said badly. Never lower the band because
          of this, and never describe it to the learner as a pronunciation
          problem: at most, one or two of the words listed may be worth
          repeating back as something to try again.
        - `filler_words_used` are hesitations the recogniser kept verbatim. A
          native speaker uses them too, so judge them by how dense they are, not
          by whether they appear.

        Transcript:
        {$transcription->text}
        PROMPT;
    }

    private function instructions(User $user): string
    {
        $language = $user->targetLanguageName();

        return <<<PROMPT
        You are an experienced CEFR examiner judging unscripted spoken {$language}.

        You receive a transcript produced by speech-to-text. Ignore punctuation,
        capitalisation and obvious recognition slips: the learner is being judged
        on their language, not on the transcriber.

        Weigh the range and accuracy of grammar, the range of vocabulary, and how
        well the ideas are connected. Choose exactly one band from A1, A2, B1, B2
        and C1 — C2 is out of scope. Be strict but fair: pick the band the evidence
        clearly supports, and prefer the lower band when the sample is thin or
        ambiguous.

        Then write, for the learner to read:
        - summary: two or three sentences in simple English, naming the band and why.
        - strengths: two to four concrete things they already do well.
        - weaknesses: two to four grammar or vocabulary topics worth working on next.
        PROMPT;
    }

    /**
     * @return Closure(JsonSchema): array<string, mixed>
     */
    private function schema(): Closure
    {
        return static fn (JsonSchema $schema): array => [
            'cefr_level' => $schema->string()->enum(CefrLevel::class)->required(),
            'summary' => $schema->string()->required(),
            'strengths' => $schema->array()->items($schema->string())->required(),
            'weaknesses' => $schema->array()->items($schema->string())->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $structured
     *
     * @throws AssessmentAnalysisFailed when the model ignored the schema.
     */
    private function result(array $structured): AssessmentResult
    {
        try {
            $validated = Validator::make($structured, [
                'cefr_level' => ['required', 'string', Rule::in(array_column(CefrLevel::cases(), 'value'))],
                'summary' => ['required', 'string'],
                'strengths' => ['required', 'array', 'min:1'],
                'strengths.*' => ['string'],
                'weaknesses' => ['required', 'array', 'min:1'],
                'weaknesses.*' => ['string'],
            ])->validate();
        } catch (ValidationException $exception) {
            throw new AssessmentAnalysisFailed(
                'The analysis model returned an unusable judgement: '.json_encode($structured),
                previous: $exception,
            );
        }

        return new AssessmentResult(
            level: CefrLevel::from($validated['cefr_level']),
            summary: $validated['summary'],
            strengths: array_values($validated['strengths']),
            weaknesses: array_values($validated['weaknesses']),
        );
    }
}
