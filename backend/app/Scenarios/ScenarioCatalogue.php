<?php

namespace App\Scenarios;

use App\Models\CustomScenario;
use App\Models\User;
use RuntimeException;

/**
 * Versioned reference content for role-play scenarios (plan §5), plus the
 * learner's confirmed custom scenarios.
 *
 * `resources/scenarios/{language}.php` carries the setting, both roles, the
 * AI's goal, the complications it may introduce and the phrases shown to the
 * learner. The catalogue is deliberately not in the database: it is content,
 * so it is reviewed and versioned like code, and
 * `voice_sessions.scenario_code` is only a plain string that points at it.
 *
 * Confirmed custom scenarios live in `custom_scenarios` scoped to one learner
 * and are resolved through `forUser()` / `findForUser()` alongside the
 * built-in catalogue.
 *
 * A language without a file simply has no scenarios yet. Language codes are
 * validated before they touch the filesystem so a value from a request cannot
 * traverse out of the resources directory.
 */
final class ScenarioCatalogue
{
    /**
     * @var array<string, array<string, ScenarioContent>>
     */
    private array $languages = [];

    /**
     * Every scenario for a language, in the order the topic list shows them.
     *
     * @return list<ScenarioContent>
     */
    public function forLanguage(string $language): array
    {
        return array_values($this->entries($language));
    }

    /**
     * Every scenario a learner can practise: their confirmed custom scenarios
     * (newest first) followed by the built-in catalogue for their target language.
     *
     * @return list<ScenarioContent>
     */
    public function forUser(User $user): array
    {
        $language = (string) $user->target_language;

        $custom = $user->customScenarios()
            ->confirmed()
            ->where('language', $language)
            ->latest('confirmed_at')
            ->latest('id')
            ->get()
            ->map(static fn (CustomScenario $scenario): ScenarioContent => $scenario->toScenarioContent())
            ->all();

        return [
            ...$this->forLanguage($language),
            ...$custom,
        ];
    }

    public function find(string $language, string $code): ?ScenarioContent
    {
        return $this->entries($language)[$code] ?? null;
    }

    public function findForUser(User $user, string $code): ?ScenarioContent
    {
        $language = (string) $user->target_language;

        if ($builtIn = $this->find($language, $code)) {
            return $builtIn;
        }

        $custom = $user->customScenarios()
            ->confirmed()
            ->where('language', $language)
            ->where('code', $code)
            ->first();

        return $custom?->toScenarioContent();
    }

    /**
     * @return array<string, ScenarioContent>
     */
    private function entries(string $language): array
    {
        if (isset($this->languages[$language])) {
            return $this->languages[$language];
        }

        if (preg_match('/^[a-z]{2,5}(-[A-Za-z0-9]{2,8})?$/', $language) !== 1) {
            return $this->languages[$language] = [];
        }

        $path = resource_path("scenarios/{$language}.php");

        if (! is_file($path)) {
            return $this->languages[$language] = [];
        }

        $data = require $path;

        if (! is_array($data)) {
            throw new RuntimeException("The scenario catalogue at {$path} must return an array.");
        }

        $entries = [];

        foreach ($data as $code => $entry) {
            if (! is_string($code) || ! is_array($entry)) {
                throw new RuntimeException("The scenario catalogue at {$path} must map a string code to an array of content.");
            }

            $entries[$code] = ScenarioContent::fromArray($language, $code, $entry);
        }

        return $this->languages[$language] = $entries;
    }
}
