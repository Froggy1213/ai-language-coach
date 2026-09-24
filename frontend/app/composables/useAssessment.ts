import {
  ASSESSMENT_READY_SUBSCRIPTION,
  CREATE_ASSESSMENT_UPLOAD_URL_MUTATION,
  SUBMIT_ASSESSMENT_MUTATION,
} from '~/graphql/documents'
import type { Assessment, PresignedUpload } from '~/types/graphql'
import { csrfAwareFetch } from '~/utils/csrf-fetch'

/**
 * The onboarding assessment, from the browser's side (plan §5).
 *
 * Three steps with three different transports: presign and submit are ordinary
 * GraphQL mutations, the upload is a multipart POST straight to the bucket
 * (the file never passes through the API), and the result arrives over a
 * Lighthouse subscription rather than as the mutation's answer.
 */
export function useAssessment() {
  const { $urql, $echo } = useNuxtApp()
  const config = useRuntimeConfig()
  const backendUrl = String(config.public.backendUrl).replace(/\/$/, '')

  async function submit(recording: Blob, contentType: string): Promise<Assessment> {
    const presigned = await $urql
      .mutation<{ createAssessmentUploadUrl: PresignedUpload }>(CREATE_ASSESSMENT_UPLOAD_URL_MUTATION, { contentType })
      .toPromise()

    if (presigned.error || !presigned.data) {
      throw presigned.error ?? new Error('Сервер не выдал разрешение на загрузку.')
    }

    const upload = presigned.data.createAssessmentUploadUrl
    const body = new FormData()

    for (const field of upload.fields) {
      body.append(field.name, field.value)
    }

    // The bucket requires the file to be the last part of the form.
    body.append('file', recording, 'recording')

    const uploaded = await fetch(upload.uploadUrl, { method: 'POST', body })

    if (!uploaded.ok) {
      throw new Error(`Загрузка записи не удалась (${uploaded.status}).`)
    }

    const submitted = await $urql
      .mutation<{ submitAssessment: Assessment }>(SUBMIT_ASSESSMENT_MUTATION, { audioUrl: upload.fileUrl })
      .toPromise()

    if (submitted.error || !submitted.data) {
      throw submitted.error ?? new Error('Не удалось отправить запись на разбор.')
    }

    return submitted.data.submitAssessment
  }

  /**
   * Wait for the analysis to finish.
   *
   * The subscription is opened over HTTP with the socket id in a header, which
   * is how Lighthouse knows which Pusher connection the result belongs to; the
   * returned channel is then authorized through Echo.
   *
   * The authorization is what makes this channel real, so it is awaited: the
   * upload starts only once Echo reports the subscription as live. Without that
   * wait a failed handshake — a refused origin, a 419, a missing Reverb — is
   * invisible here, and the screen waits for a push that can never arrive.
   */
  async function onReady(userId: string, handler: (assessment: Assessment) => void): Promise<() => void> {
    const socketId = await connectedSocketId()

    const response = await csrfAwareFetch(backendUrl, `${backendUrl}/graphql`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Socket-ID': socketId },
      body: JSON.stringify({ query: ASSESSMENT_READY_SUBSCRIPTION, variables: { userId } }),
    })

    const body = await response.json()
    const channelName = body?.extensions?.lighthouse_subscriptions?.channel as string | undefined

    if (!channelName) {
      throw new Error('Сервер не подтвердил подписку на результат.')
    }

    const name = channelName.replace(/^private-/, '')
    const channel = $echo.private(name)

    // Lighthouse's Pusher broadcaster wraps the execution result as
    // `{ more, result }` — the assessment is under `result.data`, not `data`,
    // so reading `payload.data` here is a listener that never fires.
    channel.listen('.lighthouse-subscription', (payload: { result?: { data?: { assessmentReady?: Assessment | null } } }) => {
      const assessment = payload?.result?.data?.assessmentReady

      if (assessment) {
        handler(assessment)
      }
    })

    await confirmSubscription(channel)

    return () => {
      $echo.leave(name)
    }
  }

  /**
   * Echo reports a private channel as subscribed only after pusher-js has
   * authorized it and the server has confirmed; until then nothing is listening
   * on the other end of a push.
   */
  async function confirmSubscription(
    channel: ReturnType<typeof $echo.private>,
    timeoutMs = 10000,
  ): Promise<void> {
    await new Promise<void>((resolve, reject) => {
      const timeout = setTimeout(
        () => reject(new Error(
          'Не удалось подписаться на результат: проверьте, что запущен reverb:start и что '
          + '/graphql/subscriptions/auth доступен браузеру.',
        )),
        timeoutMs,
      )

      channel.subscribed(() => {
        clearTimeout(timeout)
        resolve()
      })
    })
  }

  /**
   * The socket id has to exist before the subscription is registered, otherwise
   * the result is pushed to a connection nobody is listening on.
   */
  async function connectedSocketId(timeoutMs = 5000): Promise<string> {
    const socketId = $echo.socketId()

    if (socketId) {
      return socketId
    }

    await new Promise<void>((resolve, reject) => {
      const timeout = setTimeout(
        () => reject(new Error('WebSocket не подключился: проверьте, запущен ли Reverb и задан ли NUXT_PUBLIC_REVERB_APP_KEY.')),
        timeoutMs,
      )

      $echo.connector.pusher.connection.bind('connected', () => {
        clearTimeout(timeout)
        resolve()
      })
    })

    return $echo.socketId() ?? ''
  }

  return { submit, onReady }
}
