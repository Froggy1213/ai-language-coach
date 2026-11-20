import type { CombinedError } from '@urql/vue'
import { errors as errorsCopy, lesson, paywall, talk } from '~/constants/strings'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'

/**
 * Maps a failed voice-token request to learner-facing copy.
 *
 * The backend separates the reasons a session cannot start into GraphQL error
 * codes. Fleet-busy and a vanished scenario reuse the shared copy for their
 * mode; the rest are specific to starting a conversation. The shared mapper
 * still owns validation errors, unknown codes and the final fallback.
 */
export function mapVoiceError(failure: CombinedError): string {
  return graphQLErrorMessageFor(failure, {
    codes: {
      VOICE_FLEET_BUSY: lesson.fleetBusy,
      VOICE_START_FAILED: errorsCopy.voiceStartFailed,
      VOICE_DAILY_LIMIT_REACHED: errorsCopy.voiceDailyLimitReached,
      VOICE_MINUTES_EXHAUSTED: paywall.title,
      VOICE_SUBSCRIPTION_INACTIVE: paywall.inactiveTitle,
      LESSON_CARD_NOT_FOUND: errorsCopy.lessonCardNotFound,
      LESSON_CARD_LOCKED: errorsCopy.lessonCardLocked,
      // The catalogue can change between the picker render and the tap.
      SCENARIO_NOT_FOUND: talk.notFoundBody,
      PRACTICE_SUBJECT_INVALID: errorsCopy.practiceSubjectInvalid,
      REVIEW_ITEM_NOT_FOUND: errorsCopy.reviewItemNotFound,
      GRAMMAR_POINT_NOT_FOUND: errorsCopy.grammarPointNotFound,
    },
    messages: {
      'This lesson card does not exist.': errorsCopy.lessonCardNotFound,
      'This lesson card is not ready to practise yet.': errorsCopy.lessonCardNotReady,
    },
    fallback: errorsCopy.voiceStartFallback,
  })
}
