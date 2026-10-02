<?php

namespace App\Mistakes;

use App\Models\GrammarPoint;
use App\Models\User;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\StructuredAgentResponse;

use function Laravel\Ai\agent;

/**
 * Analyzes voice session transcripts for grammatical mistakes using DeepSeek (plan §5).
 *
 * Constrains the prompt to the canonical grammar catalogue for the learner's target language,
 * with the "uncategorized" sentinel as fallback for unmapped errors.
 *
 * Because `laravel/ai` does not validate structured output against the schema, the response
 * is rigorously validated here before any mistake is recorded (README, decision 16).
 */
final class DeepSeekMistakeAnalyzer implements MistakeAnalyzer
{
    public function analyze(string $transcript, User $user): MistakeAnalysisResult
    {
        /** @var Collection<string, GrammarPoint> $grammarPoints */
        $grammarPoints = GrammarPoint::query()
            ->where('language', $user->target_language)
            ->get()
            ->keyBy('code');

        $sentinel = $grammarPoints->get(GrammarPoint::UNCATEGORIZED_CODE);
        if (! $sentinel instanceof GrammarPoint) {
            $sentinel = GrammarPoint::updateOrCreate(
                [
                    'language' => $user->target_language,
                    'code' => GrammarPoint::UNCATEGORIZED_CODE,
                ],
                [
                    'title' => 'Uncategorized',
                    'category' => 'uncategorized',
                ],
            );
            $grammarPoints->put(GrammarPoint::UNCATEGORIZED_CODE, $sentinel);
        }

        $allowedCodes = array_values($grammarPoints->pluck('code')->all());

        $response = agent($this->instructions($user, $grammarPoints), schema: $this->schema($allowedCodes))
            ->prompt(
                $transcript,
                [],
                (string) config('assessments.mistake_analysis.provider'),
                (string) config('assessments.mistake_analysis.model'),
                (int) config('assessments.mistake_analysis.timeout_seconds'),
            );

        if (! $response instanceof StructuredAgentResponse) {
            throw new MistakeAnalysisFailed('The mistake analysis agent returned an unstructured response.');
        }

        return $this->validateAndBuildResult($response->structured, $grammarPoints, $sentinel);
    }

    /**
     * @param  Collection<string, GrammarPoint>  $grammarPoints
     */
    private function instructions(User $user, Collection $grammarPoints): string
    {
        $languageName = config("languages.names.{$user->target_language}") ?? mb_strtoupper($user->target_language);

        $catalogueLines = [];
        foreach ($grammarPoints as $gp) {
            $catalogueLines[] = "- id: {$gp->id}, code: \"{$gp->code}\", title: \"{$gp->title}\"";
        }
        $catalogueText = implode("\n", $catalogueLines);

        return <<<PROMPT
        You are an expert language teacher and CEFR assessor analyzing unscripted spoken {$languageName} from a language learner.

        Your task is to identify grammatical mistakes made by the learner in the provided transcript.
        For each genuine grammatical mistake in the learner's utterances:
        1. Identify the exact user utterance containing the mistake (`user_utterance`).
        2. Provide the corrected version of the utterance (`correction`).
        3. Provide a clear and concise explanation in English of why it is incorrect and what rule applies (`explanation`).
        4. Map the mistake to exactly one grammar point code from the allowed canonical catalogue below (`grammar_point_code`).

        Allowed Canonical Grammar Points for {$languageName}:
        {$catalogueText}

        Rules:
        - Only report genuine grammatical mistakes made by the learner. Ignore punctuation, capitalisation, and conversational filler words.
        - You MUST map each mistake to an allowed code from the catalogue. If an error does not confidently match any specific grammar point, you MUST use the code "uncategorized".
        - If the learner made no grammatical mistakes, return an empty list of mistakes.
        PROMPT;
    }

    /**
     * @param  list<string>  $allowedCodes
     * @return Closure(JsonSchema): array<string, mixed>
     */
    private function schema(array $allowedCodes): Closure
    {
        return static fn (JsonSchema $schema): array => [
            'mistakes' => $schema->array()->items(
                $schema->object([
                    'grammar_point_code' => $schema->string()->enum($allowedCodes)->required(),
                    'user_utterance' => $schema->string()->required(),
                    'correction' => $schema->string()->required(),
                    'explanation' => $schema->string()->required(),
                ])
            )->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  Collection<string, GrammarPoint>  $grammarPoints
     *
     * @throws MistakeAnalysisFailed when the response fails validation.
     */
    private function validateAndBuildResult(
        array $structured,
        Collection $grammarPoints,
        GrammarPoint $sentinel,
    ): MistakeAnalysisResult {
        try {
            $validated = Validator::make($structured, [
                'mistakes' => ['present', 'array'],
                'mistakes.*' => ['required', 'array'],
                'mistakes.*.user_utterance' => ['required', 'string'],
                'mistakes.*.correction' => ['required', 'string'],
                'mistakes.*.explanation' => ['required', 'string'],
                'mistakes.*.grammar_point_code' => ['required', 'string'],
            ])->validate();
        } catch (ValidationException $exception) {
            throw new MistakeAnalysisFailed(
                'The mistake analysis model returned an unusable response: '.json_encode($structured),
                previous: $exception,
            );
        }

        $analyzedMistakes = [];
        foreach ($validated['mistakes'] as $item) {
            $code = trim((string) $item['grammar_point_code']);
            $userUtterance = trim((string) $item['user_utterance']);
            $correction = trim((string) $item['correction']);
            $explanation = trim((string) $item['explanation']);

            if ($userUtterance === '' || $correction === '' || $explanation === '' || $code === '') {
                throw new MistakeAnalysisFailed('The mistake analysis model returned empty fields.');
            }

            // An unknown code from the model becomes the uncategorized sentinel rather than a crash or a dropped row
            $gp = $grammarPoints->get($code, $sentinel);

            $analyzedMistakes[] = new AnalyzedMistake(
                grammarPoint: $gp,
                userUtterance: $userUtterance,
                correction: $correction,
                explanation: $explanation,
            );
        }

        return new MistakeAnalysisResult($analyzedMistakes);
    }
}
