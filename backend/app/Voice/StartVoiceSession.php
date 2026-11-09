<?php

namespace App\Voice;

use App\Enums\LessonCardStatus;
use App\Enums\VoiceSessionStatus;
use App\Models\LessonCard;
use App\Models\User;
use App\Models\VoiceSession;
use App\Scenarios\ScenarioCatalogue;
use App\Scenarios\ScenarioContent;
use GraphQL\Error\Error;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Starts a voice practice session (plan §5, `requestVoiceToken`).
 *
 * The order of the five steps matters and is the whole point of the resolver:
 * a learner must never get a token for a room no agent will enter, and a retry
 * (double-click, flaky network, React re-render) must never open a second room.
 *
 * The resolver is the GraphQL seam only: it authenticates, turns the arguments
 * into exactly one `PracticeSubject` (a lesson card or a scenario), applies the
 * idempotency guard, delegates the start to `VoiceSessionStarter`, and turns
 * that starter's domain failures into the typed errors the client switches on.
 * The lock, the quota, the row and the LiveKit calls live behind the starter
 * and its collaborators.
 */
final class StartVoiceSession
{
    public function __construct(
        private readonly VoiceSessionStarter $starter,
        private readonly LiveKitApi $api,
        private readonly VoiceSessionLifecycle $lifecycle,
        private readonly ScenarioCatalogue $scenarios,
    ) {}

    /**
     * @param  array{lessonCardId?: string|null, scenarioCode?: string|null}  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): VoiceSession
    {
        $user = $context->user();
        assert($user instanceof User);

        $subject = $this->subject($user, $args);

        // 2. Idempotency guard. A session that is pending or active is still the
        //    learner's current conversation for this subject, so it is returned
        //    instead of a new one being created — the token is simply re-minted
        //    for its room.
        //
        //    An `active` session can, however, outlive its agent: the worker
        //    exits (crash, scenario finished) while the row still says active.
        //    Handing that room back would put the learner in a silent call, so
        //    it is marked abandoned and a fresh room is opened instead. A
        //    `pending` session is exempt: its agent may simply not have arrived
        //    yet, and failing the wait here would undo the idempotency guard.
        if ($existing = $this->starter->currentSession($user, $subject)) {
            if ($existing->status !== VoiceSessionStatus::Active) {
                return $existing;
            }

            // A flaky LiveKit API must not kill a live conversation, so an
            // unknown room state is treated as "still alive" and the session is
            // handed back unchanged.
            try {
                $agentPresent = $this->agentIsPresent($existing);
            } catch (ConnectionException) {
                return $existing;
            }

            if ($agentPresent) {
                return $existing;
            }

            $this->lifecycle->abandoned($existing->getKey());
        }

        return $this->start($user, $subject);
    }

    /**
     * 1. The arguments name exactly one subject.
     *
     * `lessonCardId` and `scenarioCode` are mutually exclusive: both or neither
     * is a client bug, reported as a typed error rather than silently picking
     * one. Blank strings count as absent — the frontend passes `null` for the
     * mode it is not in, but a URL query or a form can easily hand over `''`,
     * and treating that as a scenario named "" would produce a confusing
     * SCENARIO_NOT_FOUND instead of the XOR error.
     *
     * @param  array{lessonCardId?: string|null, scenarioCode?: string|null}  $args
     */
    private function subject(User $user, array $args): PracticeSubject
    {
        $lessonCardId = $this->suppliedIdentifier($args['lessonCardId'] ?? null);
        $scenarioCode = $this->suppliedIdentifier($args['scenarioCode'] ?? null);

        if (($lessonCardId === null) === ($scenarioCode === null)) {
            throw new Error(
                'Provide exactly one of lessonCardId or scenarioCode.',
                extensions: ['code' => 'PRACTICE_SUBJECT_INVALID'],
            );
        }

        if ($lessonCardId !== null) {
            return PracticeSubject::lesson($this->practisableCard($user, $lessonCardId));
        }

        assert($scenarioCode !== null);

        return PracticeSubject::scenario($this->practisableScenario($user, $scenarioCode));
    }

    /**
     * Hand the start to the starter and preserve the GraphQL error contract.
     *
     * The domain exceptions are `ClientAware`, but Lighthouse would let them
     * escape without `extensions.code`, which is what the frontend switches on,
     * so each is wrapped in a GraphQL error carrying its code and the original
     * exception as `previous`.
     */
    private function start(User $user, PracticeSubject $subject): VoiceSession
    {
        try {
            return $this->starter->start($user, $subject);
        } catch (VoiceDailyLimitReached $exception) {
            throw new Error(
                $exception->getMessage(),
                extensions: ['code' => 'VOICE_DAILY_LIMIT_REACHED'],
                previous: $exception,
            );
        } catch (VoiceFleetBusy $exception) {
            throw new Error(
                $exception->getMessage(),
                extensions: ['code' => 'VOICE_FLEET_BUSY'],
                previous: $exception,
            );
        } catch (VoiceStartFailed $failure) {
            throw new Error(
                $failure->getMessage(),
                extensions: ['code' => 'VOICE_START_FAILED'],
                previous: $failure,
            );
        }
    }

    /**
     * 1a. The card must belong to the learner and be practisable.
     *
     * A completed card stays practisable: "practice again" is a product
     * requirement, so a lesson that has been finished once can be spoken again
     * instead of the card's completed status blocking it. A locked card is
     * still refused.
     *
     * A card in another roadmap is reported as not found rather than forbidden,
     * so the error does not confirm that somebody else's card exists.
     */
    private function practisableCard(User $user, string $lessonCardId): LessonCard
    {
        $card = LessonCard::query()
            ->whereKey($lessonCardId)
            ->forUser($user)
            ->first();

        if (! $card instanceof LessonCard) {
            throw ValidationException::withMessages([
                'lessonCardId' => 'This lesson card does not exist.',
            ]);
        }

        if (! in_array($card->status, [LessonCardStatus::Ready, LessonCardStatus::Completed], true)) {
            throw ValidationException::withMessages([
                'lessonCardId' => 'This lesson card is not ready to practise yet.',
            ]);
        }

        return $card;
    }

    /**
     * 1b. The scenario must exist in the catalogue for the learner's language.
     *
     * Scenarios are versioned content files rather than rows, so the catalogue
     * is the only authority on which codes exist. An unknown code is a separate
     * typed error (`SCENARIO_NOT_FOUND`) rather than a generic validation
     * failure: the client can then send the learner back to the topic list
     * instead of showing a broken screen.
     */
    private function practisableScenario(User $user, string $code): ScenarioContent
    {
        $scenario = $this->scenarios->findForUser($user, $code);

        if (! $scenario instanceof ScenarioContent) {
            throw new Error(
                'This scenario does not exist.',
                extensions: ['code' => 'SCENARIO_NOT_FOUND'],
            );
        }

        return $scenario;
    }

    /**
     * The argument as a non-blank string, or null when it was not supplied.
     */
    private function suppliedIdentifier(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Whether a dispatched worker is still in the session's room.
     *
     * The agent is the only participant the backend can observe here: the
     * learner's token is minted just before they dial in, so the agent being
     * gone means the conversation is over even though the row still says
     * active. Matching is left to `LiveKitApi::participants()`, which filters on
     * the participant kind rather than on an identity prefix.
     */
    private function agentIsPresent(VoiceSession $session): bool
    {
        return $this->api->participants($session->room_name) !== [];
    }
}
