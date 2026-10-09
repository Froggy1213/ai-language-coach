import { getCurrentScope, onScopeDispose } from 'vue'
import { csrfAwareFetch } from '~/utils/csrf-fetch'

export interface LighthouseSubscriptionHandle {
  stop: () => void
}

export interface LighthouseTransport {
  echo: any
  backendUrl: string
}

export interface OpenLighthouseSubscriptionOptions<TEvent> {
  query: string
  variables: Record<string, unknown>
  event: string // GraphQL root field name, e.g. 'assessmentReady'
  onEvent: (payload: TEvent) => void
  socketTimeoutMs?: number // default 5000
  subscribeTimeoutMs?: number // default 10000
}

/**
 * Resolves the Pusher connection socket ID from Laravel Echo.
 *
 * The socket ID must exist before the subscription is registered with Lighthouse via HTTP POST;
 * otherwise Lighthouse cannot know which WebSocket connection the subscription belongs to,
 * and pushed results would target an unknown or unassociated connection.
 */
function waitForSocketId(echo: any, timeoutMs = 5000): Promise<string> {
  const socketId = echo?.socketId?.()

  if (socketId) {
    return Promise.resolve(socketId)
  }

  return new Promise<string>((resolve, reject) => {
    const pusherConnection = echo?.connector?.pusher?.connection

    if (!pusherConnection) {
      reject(
        new Error(
          'WebSocket не подключился: проверьте, запущен ли Reverb и задан ли NUXT_PUBLIC_REVERB_APP_KEY.',
        ),
      )
      return
    }

    const onConnected = () => {
      clearTimeout(timeout)
      pusherConnection.unbind('connected', onConnected)
      resolve(echo.socketId() ?? '')
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
}

/**
 * Awaits Echo private channel handshake and authorization confirmation.
 *
 * Echo reports a private channel as subscribed only after pusher-js has
 * authorized it via /graphql/subscriptions/auth and the server has confirmed.
 * Until then nothing is listening on the other end of a push.
 *
 * Without awaiting this handshake, failures (e.g. CSRF 419, refused origin,
 * unauthenticated session, or missing Reverb worker) remain silent, leaving
 * UI screens waiting indefinitely for a push that will never arrive.
 */
function waitForSubscriptionHandshake(
  channel: any,
  timeoutMs = 10000,
): Promise<void> {
  return new Promise<void>((resolve, reject) => {
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

    if (typeof channel?.error === 'function') {
      channel.error((err: any) => {
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

function resolveTransport(transport?: LighthouseTransport): LighthouseTransport {
  if (transport) {
    return {
      echo: transport.echo,
      backendUrl: transport.backendUrl.replace(/\/$/, ''),
    }
  }

  const { $echo } = useNuxtApp()
  const config = useRuntimeConfig()

  return {
    echo: $echo,
    backendUrl: String(config.public.backendUrl).replace(/\/$/, ''),
  }
}

/**
 * Subscribes to a Laravel Lighthouse GraphQL subscription over Echo and Reverb.
 *
 * Coordinates:
 * 1. Resolving the active Pusher socket ID.
 * 2. POSTing the subscription query via `csrfAwareFetch` with the `X-Socket-ID` header.
 * 3. Extracting the subscription channel name from `extensions.lighthouse_subscriptions.channel`.
 * 4. Subscribing to the Echo private channel (stripping the "private-" prefix).
 * 5. Listening on `.lighthouse-subscription` and unwrapping Lighthouse's `{ more, result }` envelope.
 *    Lighthouse wraps broadcast payloads as `{ more, result }` where data lives under
 *    `result.data[event]`, not directly under `data[event]`.
 * 6. Awaiting the handshake authorization confirmation with timeout and error listener.
 * 7. Ensuring the channel is left on errors or when `stop()` is invoked.
 *
 * @param options - Subscription query, variables, event field name, listener, and timeout options.
 * @param transport - Optional injected transport for testability or custom endpoints.
 * @returns A handle with an idempotent `stop()` method to leave the channel and clean up listeners.
 */
export async function openLighthouseSubscription<TEvent>(
  options: OpenLighthouseSubscriptionOptions<TEvent>,
  transport?: LighthouseTransport,
): Promise<LighthouseSubscriptionHandle> {
  const { echo, backendUrl } = resolveTransport(transport)

  const socketId = await waitForSocketId(echo, options.socketTimeoutMs ?? 5000)

  const response = await csrfAwareFetch(backendUrl, `${backendUrl}/graphql`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Socket-ID': socketId },
    body: JSON.stringify({
      query: options.query,
      variables: options.variables,
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
  const channel = echo.private(name)

  // Lighthouse wraps broadcast result in `{ more, result }` envelope (decision 21).
  // The event payload is under `result.data[event]`, so reading `payload.data` directly
  // would be a listener that never fires.
  channel.listen(
    '.lighthouse-subscription',
    (payload: { result?: { data?: Record<string, unknown> } }) => {
      const data = payload?.result?.data
      if (data && options.event in data) {
        const eventPayload = data[options.event] as TEvent | null | undefined
        if (eventPayload != null) {
          options.onEvent(eventPayload)
        }
      }
    },
  )

  try {
    await waitForSubscriptionHandshake(channel, options.subscribeTimeoutMs ?? 10000)
  } catch (err) {
    try {
      echo.leave(name)
    } catch {}
    throw err
  }

  let stopped = false
  const stop = () => {
    if (stopped) {
      return
    }
    stopped = true
    try {
      echo.leave(name)
    } catch {}
  }

  return { stop }
}

/**
 * Vue composable for managing Lighthouse subscriptions with automatic lifecycle teardown.
 *
 * Tracks open subscription handles and automatically stops all active subscriptions
 * when the component scope is disposed (on unmount).
 */
export function useLighthouseSubscription(): {
  open: <TEvent>(
    options: OpenLighthouseSubscriptionOptions<TEvent>,
  ) => Promise<LighthouseSubscriptionHandle>
  stopAll: () => void
} {
  const { $echo } = useNuxtApp()
  const config = useRuntimeConfig()
  const backendUrl = String(config.public.backendUrl).replace(/\/$/, '')
  const transport: LighthouseTransport = { echo: $echo, backendUrl }

  const activeHandles = new Set<LighthouseSubscriptionHandle>()

  function stopAll(): void {
    for (const handle of Array.from(activeHandles)) {
      handle.stop()
    }
    activeHandles.clear()
  }

  async function open<TEvent>(
    options: OpenLighthouseSubscriptionOptions<TEvent>,
  ): Promise<LighthouseSubscriptionHandle> {
    const handle = await openLighthouseSubscription<TEvent>(options, transport)

    let wrappedStopped = false
    const wrappedHandle: LighthouseSubscriptionHandle = {
      stop: () => {
        if (wrappedStopped) {
          return
        }
        wrappedStopped = true
        activeHandles.delete(wrappedHandle)
        handle.stop()
      },
    }

    activeHandles.add(wrappedHandle)
    return wrappedHandle
  }

  if (getCurrentScope()) {
    onScopeDispose(() => {
      stopAll()
    })
  }

  return {
    open,
    stopAll,
  }
}
