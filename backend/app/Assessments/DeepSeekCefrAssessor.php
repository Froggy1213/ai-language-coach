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
    /**
     * Phrases that would turn the advice into a phonetic verdict.
     *
     * The evidence is confidence, not phonemes, so a sentence built on these
     * asserts something the measurement cannot support — the exact failure the
     * published evidence describes (`benchmarks/research/voice-stack-research-2026-10-14.md`
     * §"Do not use a multimodal LLM to grade pronunciation").
     *
     * @var list<string>
     */
    private const FORBIDDEN_ADVICE_PHRASES = [
        '発音が違',
        '発音が悪',
        '発音を間違',
        '発音の間違い',
        '発音記号',
        'の発音は',
        'の発音が',
        'mispronounced',
        'wrong pronunciation',
        'pronunciation error',
    ];

    public function assess(
        Transcription $transcription,
        User $user,
        PronunciationAssessment $pronunciation,
        PronunciationAnalyzer $analyzer,
    ): AssessmentResult {
        $response = agent($this->instructions($user), schema: $this->schema())
            ->prompt(
                $this->prompt($transcription, $pronunciation, $analyzer),
                [],
                (string) config('assessments.analysis.provider'),
                (string) config('assessments.analysis.model'),
                (int) config('assessments.analysis.timeout_seconds'),
            );

        if (! $response instanceof StructuredAgentResponse) {
            throw new AssessmentAnalysisFailed('The analysis agent returned an unstructured response.');
        }

        $advice = $this->advice($response->structured['pronunciation_advice'] ?? null);

        return $this->result($response->structured)->withPronunciation(
            $pronunciation->withAdvice($advice),
        );
    }

    /**
     * The transcript, plus the measurements the words themselves cannot express.
     *
     * A batch transcription without word timings (a stubbed provider, a model
     * that does not return them) falls back to the bare transcript rather than
     * printing zeroes the caller never measured.
     *
     * The pronunciation block is rendered through `toPromptArray`, which is also
     * what decides whether there is anything to say: a recording the recogniser
     * was sure about throughout produces no block, so the model is never asked to
     * invent an observation about clarity.
     */
    private function prompt(
        Transcription $transcription,
        PronunciationAssessment $pronunciation,
        PronunciationAnalyzer $analyzer,
    ): string {
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

        // The words the recogniser struggled with, so the advice the learner reads
        // names the words that were actually unclear instead of pronunciation in
        // general. The share itself is left out: it is the interface's number, and
        // a prompt carrying it invites the model to repeat it as a grade.
        $pronunciation = json_encode(
            $analyzer->toPromptArray($pronunciation),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $unclearWords = is_string($pronunciation) && $pronunciation !== ''
            ? <<<BLOCK

            Words the recogniser was least sure about:
            {$pronunciation}

            BLOCK
            : "\n";

        return <<<PROMPT
        Recording length: {$transcription->durationSeconds} seconds.

        Measurements taken from the transcription:
        {$measurements}

        {$timings}
        {$unclearWords}
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
        - `words_the_recogniser_struggled_with` is the same evidence, reduced to
          the words worth trying again, and `sample_too_short_to_judge_overall`
          means the clip was too short for any statement about clarity as a whole.
          Write `pronunciation_advice` from this block alone.
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
        - pronunciation_advice: one or two sentences in Japanese, naming at most two
          of the words in `words_the_recogniser_struggled_with` and suggesting the
          learner say them again slowly. This is about how clearly the recording
          came through, not a verdict on the learner's mouth: the measurement is
          the recogniser's confidence, and a microphone, noise or an accent move it
          as much as an unclear word does. Never write a phonetic transcription or
          a sound in slashes, never claim the learner mispronounced something, and
          never name a word that is not in that list. When the list is absent or
          `sample_too_short_to_judge_overall` is set, return an empty string.
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
            'pronunciation_advice' => $schema->string(),
        ];
    }

    /**
     * The sentence the learner reads about clarity, or null when the model wrote
     * nothing usable.
     *
     * Deliberately not fatal. Everything else about the judgement is already
     * validated, and a missing or over-reaching sentence is not a reason to throw
     * away a level the analysis did reach: the screen then shows the words and the
     * score without a comment, which is a smaller loss than no assessment at all.
     * The rejection is reported instead, so a prompt that has drifted is visible
     * rather than silent.
     */
    private function advice(mixed $advice): ?string
    {
        if (! is_string($advice)) {
            return null;
        }

        $advice = trim($advice);

        if ($advice === '') {
            return null;
        }

        try {
            $validated = Validator::make(['advice' => $advice], [
                'advice' => [
                    'string',
                    'min:10',
                    'max:300',
                    // The interface language: a sentence the learner cannot read
                    // is not feedback, and an English one would mean the model
                    // ignored the instruction.
                    'regex:/[ぁ-んァ-ヶ一-龯]/u',
                    static function (string $attribute, mixed $value, Closure $fail): void {
                        foreach (self::FORBIDDEN_ADVICE_PHRASES as $phrase) {
                            if (mb_stripos((string) $value, $phrase) !== false) {
                                $fail("The pronunciation advice asserts a phonetic verdict (`{$phrase}`).");
                            }
                        }

                        // A phoneme in IPA or slashes is diagnosis, which the
                        // confidence evidence cannot support.
                        if (preg_match('#/[^/]{1,12}/|[\[【][a-zɑɒæɔəɘɚɛɜɝɞɨɪɯɵɶøyʉʊʌɤɥʜʢʡɕʑʃʒθðŋɲɳɴɱɾɽʀʁffsβvɣχʁħʕɦɬɮʋɹɻjɰlɭʎʟˈˌːˑ.]+[\]】]#u', (string) $value) === 1) {
                            $fail('The pronunciation advice contains a phonetic transcription.');
                        }
                    },
                ],
            ])->validate();
        } catch (ValidationException $exception) {
            report($exception);

            return null;
        }

        return $validated['advice'];
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
