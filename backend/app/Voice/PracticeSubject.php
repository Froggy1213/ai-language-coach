<?php

namespace App\Voice;

use App\Models\LessonCard;
use App\Scenarios\ScenarioContent;

/**
 * What a voice session practises: exactly one lesson card or one scenario
 * (plan §4.2).
 *
 * The constructor is private so "neither a lesson nor a scenario" is not a
 * state that has to be checked at runtime — it cannot be represented. Every
 * caller goes through the two named factories, which is also what keeps the
 * `voice_sessions` row and the agent metadata filling exactly one subject.
 */
final readonly class PracticeSubject
{
    private function __construct(
        public ?LessonCard $lessonCard,
        public ?ScenarioContent $scenario,
    ) {}

    public static function lesson(LessonCard $card): self
    {
        return new self($card, null);
    }

    public static function scenario(ScenarioContent $scenario): self
    {
        return new self(null, $scenario);
    }

    public function isLesson(): bool
    {
        return $this->lessonCard instanceof LessonCard;
    }

    /**
     * A stable, human-readable identifier for logs and metrics — not a room
     * name and never a secret.
     */
    public function label(): string
    {
        if ($this->lessonCard instanceof LessonCard) {
            return 'lesson:'.$this->lessonCard->getKey();
        }

        assert($this->scenario instanceof ScenarioContent);

        return 'scenario:'.$this->scenario->code;
    }
}
