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
                $transcription->text,
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

    private function instructions(User $user): string
    {
        $language = config("languages.names.{$user->target_language}") ?? mb_strtoupper($user->target_language);

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
