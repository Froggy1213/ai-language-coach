import type { CombinedError } from '@urql/vue'
import { computed, onMounted, onUnmounted, ref, toValue, watch, type MaybeRefOrGetter } from 'vue'
import {
  SESSION_FEEDBACK_READY_SUBSCRIPTION,
  VOICE_SESSION_QUERY,
} from '~/graphql/documents'
import type { Mistake, VoiceSession } from '~/types/graphql'
import { csrfAwareFetch } from '~/utils/csrf-fetch'
import { graphQLErrorCode, graphQLErrorMessage } from '~/utils/graphql-error'

/**
 * Manages the feedback and async mistake analysis for a voice session (plan §4–6).
 *
 * Coordinates reading the session (`voiceSession(id)`), subscribing to the async
 * analysis completion (`sessionFeedbackReady(sessionId)`) via Reverb / Lighthouse
 * envelope, waiting for the channel handshake confirmation, surfacing failed
 * handshakes honestly, and providing manual refresh fallback.
 */
export function useSessionFeedback(sessionId: MaybeRefOrGetter<string>) {
  const { $urql, $echo } = useNuxtApp()
  const config = useRuntimeConfig()
  const backendUrl = String(config.public.backendUrl).replace(/\/$/, '')

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
    if (!failure) {
      return 'Не удалось загрузить данные сессии.'
    }

    const code = graphQLErrorCode(failure)

    if (code === 'VOICE_SESSION_NOT_FOUND') {
      return 'Голосовая сессия не найдена.'
    }

    if (code === 'LESSON_CARD_NOT_FOUND') {
      return 'Урок не найден в вашем роадмапе.'
    }

    if (code === 'UNAUTHENTICATED') {
      return 'Сессия истекла. Пожалуйста, выполните вход снова.'
    }

    const serverMessage = graphQLErrorMessage(failure)
    if (serverMessage === 'Unauthenticated.') {
      return 'Сессия истекла. Пожалуйста, выполните вход снова.'
    }

    return serverMessage ?? 'Не удалось загрузить данные сессии.'
  }

  /**
   * Resolves the Pusher connection socket ID, waiting up to `timeoutMs` for
   * the socket to connect if it is not yet ready.
   */
  async function connectedSocketId(timeoutMs = 5000): Promise<string> {
    const socketId = $echo.socketId()

    if (socketId) {
      return socketId
    }

    await new Promise<void>((resolve, reject) => {
      const pusherConnection = $echo.connector.pusher.connection

      const onConnected = () => {
        clearTimeout(timeout)
        pusherConnection.unbind('connected', onConnected)
        resolve()
      }

      const timeout = setTimeout(() => {
        pusherConnection.unbind('connected', onConnected)
        reject(
          new Error(
            'WebSocket не подключился: проверьте, запущен ли Reverb и задан ли NUXT_PUBLIC_REVERB_APP_KEY.',
          ),
        )
      }, timeoutMs)

      pusherConnection.bind('connected', onConnected)
    })

    return $echo.socketId() ?? ''
  }

  /**
   * Echo reports a private channel as subscribed only after pusher-js has
   * authorized it via /graphql/subscriptions/auth and the server has confirmed.
   */
  async function confirmSubscription(
    channel: ReturnType<typeof $echo.private>,
    timeoutMs = 10000,
  ): Promise<void> {
    await new Promise<void>((resolve, reject) => {
      const timeout = setTimeout(
        () =>
          reject(
            new Error(
              'Не удалось подписаться на результат: проверьте, что запущен reverb:start и что '
              + '/graphql/subscriptions/auth доступен браузеру.',
            ),
          ),
        timeoutMs,
      )

      channel.subscribed(() => {
        clearTimeout(timeout)
        resolve()
      })

      if (typeof (channel as any).error === 'function') {
        ;(channel as any).error((err: any) => {
          clearTimeout(timeout)
          reject(
            new Error(
              err?.message ?? 'Ошибка авторизации канала подписки на результат анализа.',
            ),
          )
        })
      }
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
      const socketId = await connectedSocketId()

      if (disposed) {
        return
      }

      const response = await csrfAwareFetch(backendUrl, `${backendUrl}/graphql`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Socket-ID': socketId },
        body: JSON.stringify({
          query: SESSION_FEEDBACK_READY_SUBSCRIPTION,
          variables: { sessionId: id },
        }),
      })

      if (!response.ok) {
        throw new Error(`Сервер отклонил запрос на подписку (${response.status}).`)
      }

      const body = await response.json()

      if (body?.errors && body.errors.length > 0) {
        throw new Error(body.errors[0]?.message ?? 'Ошибка подписки на результат анализа.')
      }

      const channelName = body?.extensions?.lighthouse_subscriptions?.channel as
        | string
        | undefined

      if (!channelName) {
        throw new Error('Сервер не подтвердил канал подписки на результат.')
      }

      const name = channelName.replace(/^private-/, '')
      const channel = $echo.private(name)

      // Lighthouse wraps broadcast result in `{ more, result }` envelope (decision 21)
      channel.listen(
        '.lighthouse-subscription',
        (payload: { result?: { data?: { sessionFeedbackReady?: VoiceSession | null } } }) => {
          const updated = payload?.result?.data?.sessionFeedbackReady

          if (updated) {
            session.value = updated
            analyzing.value = false
            teardownSubscription()
          }
        },
      )

      await confirmSubscription(channel)

      stopSubscription = () => {
        $echo.leave(name)
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
