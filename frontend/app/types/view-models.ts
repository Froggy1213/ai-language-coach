import type { Assessment, CefrLevel, PronunciationFeedback, VoiceSession } from '~/types/graphql'

/**
 * Hand-written UI projection of the authenticated user in the SPA session state (`useAuth`).
 *
 * This is not a schema type because it projects only the subset of fields requested
 * by `ME_QUERY` for global auth/session state, rather than the complete GraphQL `User`.
 * Pages with richer requirements (such as `app/pages/settings.vue`) keep their own
 * query data types (e.g. `MeData`).
 */
export interface CurrentUser {
  id: string
  name: string
  email: string
  targetLanguage: string
  currentLevel: CefrLevel
  voiceConsentAt?: string | null
  latestAssessment?: LatestAssessment | null
}

/**
 * The last assessment the learner finished, as `me` returns it.
 *
 * The schema type is reused rather than restated: only the fields `ME_QUERY` and
 * the `assessmentReady` subscription actually select are present at runtime, and
 * a hand-written copy would drift from the SDL the moment either is widened.
 */
export interface LatestAssessment extends Pick<Assessment, 'id' | 'status' | 'cefrLevel'> {
  pronunciation?: PronunciationFeedback | null
}

/** The clarity bands the server reports, as the interface names them. */
export type PronunciationBand = 'clear' | 'mostly_clear' | 'uneven' | 'effortful'

/** Detail view of a voice session including mistake analysis completion flag. */
export interface VoiceSessionDetail extends VoiceSession {
  analyzed: boolean
}

