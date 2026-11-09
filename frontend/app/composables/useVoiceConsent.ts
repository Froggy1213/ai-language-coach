import { computed, ref } from 'vue'
import { voiceConsent as copy } from '~/constants/strings'
import { RECORD_VOICE_CONSENT_MUTATION } from '~/graphql/documents'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'

/**
 * Explicit consent to voice recording and processing.
 *
 * The backend refuses `requestVoiceToken` and `submitAssessment` until this
 * has been recorded, so the gate is not cosmetic: without it a newly registered
 * learner would be told to agree and then rejected by the API. `refresh()`
 * updates the shared `auth:user` state, which is what closes the gate.
 */
export function useVoiceConsent() {
  const { $urql } = useNuxtApp()
  const { user, refresh } = useAuth()

  const submitting = ref(false)
  const error = ref<string | null>(null)

  const hasConsent = computed(() => Boolean(user.value?.voiceConsentAt))

  async function giveConsent(): Promise<boolean> {
    if (hasConsent.value) {
      return true
    }

    submitting.value = true
    error.value = null

    try {
      const result = await $urql
        .mutation<{ recordVoiceConsent: { id: string; voiceConsentAt: string | null } }>(
          RECORD_VOICE_CONSENT_MUTATION,
          {},
        )
        .toPromise()

      if (result.error) {
        error.value = graphQLErrorMessageFor(result.error, { fallback: copy.error })
        return false
      }

      await refresh()

      if (!user.value?.voiceConsentAt) {
        error.value = copy.error
        return false
      }

      return true
    } catch {
      error.value = copy.error
      return false
    } finally {
      submitting.value = false
    }
  }

  return { hasConsent, submitting, error, giveConsent }
}
