<?php

namespace Tests\Unit\Scenarios;

use App\Enums\CefrLevel;
use App\Scenarios\ScenarioContent;
use App\Scenarios\ScenarioRegister;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScenarioContentTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidEntries(): array
    {
        return [
            'missing title' => [self::entry(['title' => null])],
            'empty summary' => [self::entry(['summary' => '   '])],
            'missing ai goal' => [self::entry(['ai_goal' => null])],
            'empty learner goal' => [self::entry(['learner_goal' => ''])],
            'empty opening line' => [self::entry(['opening_line' => ''])],
            'empty success criteria' => [self::entry(['success_criteria' => ''])],
            'missing level' => [self::entry(['level' => null])],
            'unknown level' => [self::entry(['level' => 'C2'])],
            'unknown register' => [self::entry(['register' => 'polite'])],
            'no complications' => [self::entry(['complications' => []])],
            'one complication' => [self::entry(['complications' => ['The bag is two kilos over the limit.']])],
            'empty complication' => [self::entry(['complications' => ['The bag is two kilos over the limit.', '']])],
            'complications is not a list' => [self::entry(['complications' => [
                'seats' => 'The first seat is a middle seat.',
                'bag' => 'The bag is two kilos over the limit.',
            ]])],
            'two useful phrases' => [self::entry(['useful_phrases' => [
                'Could I have a window seat?',
                'Will my bag go all the way through?',
            ]])],
            'six useful phrases' => [self::entry(['useful_phrases' => [
                'I have a connecting flight to Seoul.',
                'Could I have a window seat?',
                'Will my bag go all the way through?',
                'Is the flight on time?',
                'Where is the gate?',
                'Can I pay by card?',
            ]])],
            'empty useful phrase' => [self::entry(['useful_phrases' => [
                'I have a connecting flight to Seoul.',
                '',
                'Will my bag go all the way through?',
            ]])],
        ];
    }

    #[DataProvider('invalidEntries')]
    public function test_it_rejects_a_malformed_entry(array $entry): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScenarioContent::fromArray('en', 'airport_checkin', $entry);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCodes(): array
    {
        return [
            'uppercase' => ['Airport_Checkin'],
            'starts with a digit' => ['1_airport_checkin'],
            'hyphen instead of underscore' => ['airport-checkin'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidCodes')]
    public function test_it_rejects_a_code_that_is_not_a_stable_identifier(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScenarioContent::fromArray('en', $code, self::entry());
    }

    public function test_it_builds_a_scenario_that_round_trips_through_an_array(): void
    {
        $content = ScenarioContent::fromArray('en', 'airport_checkin', self::entry());

        $this->assertSame('en', $content->language);
        $this->assertSame('airport_checkin', $content->code);
        $this->assertSame(CefrLevel::A2, $content->level);
        $this->assertSame(ScenarioRegister::Formal, $content->register);
        $this->assertSame(self::entry()['complications'], $content->complications);
        $this->assertSame(self::entry()['useful_phrases'], $content->usefulPhrases);

        $this->assertSame([
            'language' => 'en',
            'code' => 'airport_checkin',
            'title' => 'Airport check-in',
            'summary' => 'Check in for a connecting flight, with a bag that is slightly too heavy.',
            'level' => 'A2',
            'register' => 'formal',
            'setting' => 'The check-in desk at Haneda airport, forty minutes before a flight to Seoul.',
            'ai_role' => 'Check-in agent. Efficient, friendly, slightly harried.',
            'ai_goal' => 'Check the passenger in before the desk closes.',
            'learner_role' => 'Passenger with one suitcase and a connection in Seoul.',
            'learner_goal' => 'Check in and keep the connection.',
            'complications' => [
                'The first seat is a middle seat in the back row.',
                'The bag is two kilos over the limit.',
            ],
            'opening_line' => 'Good evening. Passport, please — are you checking a bag today?',
            'success_criteria' => 'Passport shown, seat chosen, bag accepted, connection confirmed.',
            'useful_phrases' => [
                'I have a connecting flight to Seoul.',
                'Could I have a window seat?',
                'Will my bag go all the way through?',
            ],
        ], $content->toArray());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function entry(array $overrides = []): array
    {
        $entry = [
            'title' => 'Airport check-in',
            'summary' => 'Check in for a connecting flight, with a bag that is slightly too heavy.',
            'level' => 'A2',
            'register' => 'formal',
            'setting' => 'The check-in desk at Haneda airport, forty minutes before a flight to Seoul.',
            'ai_role' => 'Check-in agent. Efficient, friendly, slightly harried.',
            'ai_goal' => 'Check the passenger in before the desk closes.',
            'learner_role' => 'Passenger with one suitcase and a connection in Seoul.',
            'learner_goal' => 'Check in and keep the connection.',
            'complications' => [
                'The first seat is a middle seat in the back row.',
                'The bag is two kilos over the limit.',
            ],
            'opening_line' => 'Good evening. Passport, please — are you checking a bag today?',
            'success_criteria' => 'Passport shown, seat chosen, bag accepted, connection confirmed.',
            'useful_phrases' => [
                'I have a connecting flight to Seoul.',
                'Could I have a window seat?',
                'Will my bag go all the way through?',
            ],
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($entry[$key]);

                continue;
            }

            $entry[$key] = $value;
        }

        return $entry;
    }
}
