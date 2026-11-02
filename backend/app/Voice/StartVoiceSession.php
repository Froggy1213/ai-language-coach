<?php

namespace App\Voice;

use App\Enums\LessonCardStatus;
use App\Enums\VoiceSessionStatus;
use App\Models\LessonCard;
use App\Models\User;
use App\Models\VoiceSession;
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
 * The resolver is the GraphQL seam only: it authenticates, resolves the card,
 * applies the idempotency guard, delegates the start to `VoiceSessionStarter`,
 * and turns that starter's domain failures into the typed errors the client
 * switches on. The lock, the quota, the row and the LiveKit calls live behind
 * the starter and its collaborators.
 */
final class StartVoiceSession
{
    public function __construct(
        private readonly VoiceSessionStarter $starter,
        private readonly LiveKitApi $api,
        private readonly VoiceSessionLifecycle $lifecycle,
    ) {}

    /**
     * @param  array{lessonCardId: string}  $args
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): VoiceSession
    {
        $user = $context->user();
        assert($user instanceof User);

        $card = $this->practisableCard($user, (string) $args['lessonCardId']);

        // 2. Idempotency guard. A session that is pending or active is still the
        //    learner's current conversation, so it is returned instead of a new
        //    one being created — the token is simply re-minted for its room.
        //
        //    An `active` session can, however, outlive its agent: the worker
        //    exits (crash, scenario finished) while the row still says active.
        //    Handing that room back would put the learner in a silent call, so
        //    it is marked abandoned and a fresh room is opened instead. A
        //    `pending` session is exempt: its agent may simply not have arrived
        //    yet, and failing the wait here would undo the idempotency guard.
        if ($existing = $this->starter->currentSession($user, $card)) {
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

        return $this->start($user, $card);
    }

    /**
     * Hand the start to the starter and preserve the GraphQL error contract.
     *
     * The domain exceptions are `ClientAware`, but Lighthouse would let them
     * escape without `extensions.code`, which is what the frontend switches on,
     * so each is wrapped in a GraphQL error carrying its code and the original
     * exception as `previous`.
     */
    private function start(User $user, LessonCard $card): VoiceSession
    {
        try {
            return $this->starter->start($user, $card);
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
     * 1. The card must belong to the learner and be practisable.
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
