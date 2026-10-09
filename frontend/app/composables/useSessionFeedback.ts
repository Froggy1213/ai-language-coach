import type { CombinedError } from '@urql/vue'
import { computed, onMounted, onUnmounted, ref, toValue, watch, type MaybeRefOrGetter } from 'vue'
import {
  SESSION_FEEDBACK_READY_SUBSCRIPTION,
  VOICE_SESSION_QUERY,
} from '~/graphql/documents'
import type { Mistake, VoiceSession } from '~/types/graphql'
import { graphQLErrorMessageFor } from '~/utils/graphql-error'
import { useLighthouseSubscription } from '~/composables/useLighthouseSubscription'

/**
 * Manages the feedback and async mistake analysis for a voice session (plan §4–6).
 *
 * Coordinates reading the session (`voiceSession(id)`), subscribing to the async
 * analysis completion (`sessionFeedbackReady(sessionId)`) via Reverb / Lighthouse
 * envelope, waiting for the channel handshake confirmation, surfacing failed
 * handshakes honestly, and providing manual refresh fallback.
 */
export function useSessionFeedback(sessionId: MaybeRefOrGetter<string>) {
  const { $urql } = useNuxtApp()
  const lighthouse = useLighthouseSubscription()

  const session = ref<VoiceSession | null>(null)
  const loading = ref(true)
  const error = ref<string | null>(null)
  const analyzing = ref(false)

  const mistakes = computed<Mistake[]>(() => session.value?.mistakes ?? [])

  let stopSubscription: (() => void) | null = null
  let disposed = false

  function teardownSubscription(): void {
    if (stopSubscription) {
      stopSubscription()
      stopSubscription = null
    }
  }

  function mapSessionError(failure: CombinedError | null | undefined): string {
    return graphQLErrorMessageFor(failure, {
      codes: {
        VOICE_SESSION_NOT_FOUND: 'Голосовая сессия не найдена.',
        LESSON_CARD_NOT_FOUND: 'Урок не найден в вашем роадмапе.',
        UNAUTHENTICATED: 'Сессия истекла. Пожалуйста, выполните вход снова.',
      },
      fallback: 'Не удалось загрузить данные сессии.',
    })
  }

  /**
   * Subscribes to the async mistake analysis completion via Lighthouse subscription.
   */
  async function subscribe(id: string): Promise<void> {
    teardownSubscription()

    if (!import.meta.client || disposed) {
      return
    }

    try {
      const handle = await lighthouse.open<VoiceSession>({
        query: SESSION_FEEDBACK_READY_SUBSCRIPTION,
        variables: { sessionId: id },
        event: 'sessionFeedbackReady',
        onEvent: (updated) => {
          session.value = updated
          analyzing.value = false
          teardownSubscription()
        },
      })

      if (disposed) {
        handle.stop()
        return
      }

      stopSubscription = () => {
        handle.stop()
      }
    } catch (subErr: any) {
      // Failed handshake: report on screen instead of waiting forever
      error.value = subErr?.message ?? 'Не удалось подписаться на обновления анализа.'
      analyzing.value = false
      teardownSubscription()
    }
  }

  /**
   * Loads the voice session from the GraphQL API.
   */
  async function loadSession(): Promise<void> {
    const id = toValue(sessionId)

    if (!id) {
      session.value = null
      loading.value = false
      analyzing.value = false
      return
    }

    try {
      const result = await $urql
        .query<{ voiceSession: VoiceSession | null }>(
          VOICE_SESSION_QUERY,
          { id },
          { requestPolicy: 'network-only' },
        )
        .toPromise()

      if (disposed) {
        return
      }

      if (result.error) {
        error.value = mapSessionError(result.error)
        session.value = null
        analyzing.value = false
        return
      }

      const current = result.data?.voiceSession ?? null
      session.value = current

      if (!current) {
        // Not found or doesn't belong to authenticated user
        analyzing.value = false
        return
      }

      const isTerminal =
        current.status === 'completed'
        || current.status === 'abandoned'
        || current.status === 'failed'

      if (isTerminal) {
        if (current.mistakes && current.mistakes.length > 0) {
          analyzing.value = false
          teardownSubscription()
        } else {
          // Terminal session with no mistakes yet: async analysis may still be in flight
          analyzing.value = true
          void subscribe(id)
        }
      } else {
        analyzing.value = false
        teardownSubscription()
      }
    } catch {
      if (!disposed) {
        error.value = 'Не удалось загрузить данные сессии.'
        analyzing.value = false
      }
    } finally {
      if (!disposed) {
        loading.value = false
      }
    }
  }

  /**
   * Manual refresh fallback: re-reads the session and turns off analyzing if
   * mistakes are found or if the user explicitly checks.
   */
  async function refresh(): Promise<void> {
    error.value = null
    loading.value = true
    try {
      await loadSession()
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    void loadSession()
  })

  watch(
    () => toValue(sessionId),
    () => {
      teardownSubscription()
      error.value = null
      loading.value = true
      void loadSession()
    },
  )

  onUnmounted(() => {
    disposed = true
    teardownSubscription()
  })

  return {
    session,
    mistakes,
    loading,
    error,
    analyzing,
    refresh,
  }
}
