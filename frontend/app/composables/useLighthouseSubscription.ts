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
 * The message for "the socket never came up".
 *
 * Shared because two independent paths raise it — the socket-ID wait and the
 * channel handshake — and a learner who hits both should read one sentence, not
 * two versions of it.
 */
const SOCKET_UNREACHABLE
  = 'リアルタイム接続を確立できませんでした。しばらくしてからもう一度お試しください。'

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
    return socketId
  }

  return new Promise<string>((resolve, reject) => {
    const pusherConnection = echo?.connector?.pusher?.connection

    if (!pusherConnection) {
      reject(new Error(SOCKET_UNREACHABLE))
      return
    }

    const onConnected = () => {
      clearTimeout(timeout)
      pusherConnection.unbind('connected', onConnected)
      resolve(echo.socketId() ?? '')
    }

    const timeout = setTimeout(() => {
      pusherConnection.unbind('connected', onConnected)
      reject(new Error(SOCKET_UNREACHABLE))
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
      () => reject(new Error(SOCKET_UNREACHABLE)),
      timeoutMs,
    )

    channel.subscribed(() => {
      clearTimeout(timeout)
      resolve()
    })

    if (typeof channel?.error === 'function') {
      channel.error((err: any) => {
        clearTimeout(timeout)
        reject(new Error(err?.message ?? 'チャンネルの認証に失敗しました。'))
      })
    }
  })
}

/**
 * Watch the Pusher connection and report reconnects.
 *
 * This is the gap a Lighthouse subscription cannot close by itself: a Pusher
 * channel is re-subscribed to automatically after the socket reconnects, but the
 * *Lighthouse* subscription is registered server-side against the old socket id.
 * When the socket comes back with a new id, Lighthouse keeps pushing at the
 * connection that no longer exists, so a result produced after a reconnect is
 * never delivered — the screen sits on "analysing" forever with no error to show.
 *
 * The first `connected` is the connection the caller is already using, so it is
 * not reported: only a reconnect is. The listener is removed by the returned
 * function, which callers wire to component teardown.
 *
 * @param echo     The Laravel Echo instance.
 * @param onReconnect Called once per *re*-connection, not the initial one.
 * @returns A function that stops watching.
 */
export function watchPusherReconnects(echo: any, onReconnect: () => void): () => void {
  const connection = echo?.connector?.pusher?.connection

  // A transport that does not expose a connection (a test double, an Echo
  // connector other than Pusher) simply has nothing to watch. Failing here would
  // turn an optional resilience path into a broken screen.
  if (!connection || typeof connection.bind !== 'function') {
    return () => {}
  }

  // A page that loads while the socket is already up — a single-page app can
  // arrive here with the connection long established — must still report its
  // *next* reconnect. Starting this at `false` unconditionally would treat that
  // next transition into `connected` as the initial connection and swallow the
  // only reconnect that mattered, leaving the screen stuck exactly as before.
  let seenConnected = connection.state === 'connected'

  const onStateChange = (states: { current?: string; previous?: string }) => {
    if (states?.current !== 'connected') {
      return
    }

    if (!seenConnected) {
      seenConnected = true
      return
    }

    onReconnect()
  }

  // `state_change` rather than `connected`: the handler has to run for a
  // transition into `connected` from anywhere, and the first one has to be
  // distinguishable from the rest.
  connection.bind('state_change', onStateChange)

  return () => {
    try {
      connection.unbind('state_change', onStateChange)
    } catch {
      // Already torn down by the transport; nothing left to unbind.
    }
  }
}

function resolveTransport(transport?: LighthouseTransport): LighthouseTransport {
  if (transport) {
    return {
      echo: transport.echo,
      backendUrl: transport.backendUrl.replace(/\/$/, ''),
    }
  }

  const { $echo } = useNuxtApp()

  return {
    echo: $echo,
    backendUrl: useBackendUrl(),
  }
}

/**
 * Subscribes to a Laravel Lighthouse GraphQL subscription over Echo and Reverb.
 *
 * Coordinates:
 * 1. Opening the socket if it is not up yet, and resolving the Pusher socket ID.
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
    throw new Error(`サブスクリプションの登録がサーバーに拒否されました（${response.status}）。`)
  }

  const body = await response.json()

  if (body?.errors && body.errors.length > 0) {
    throw new Error(body.errors[0]?.message ?? 'サブスクリプションを登録できませんでした。')
  }

  const channelName = body?.extensions?.lighthouse_subscriptions?.channel as
    | string
    | undefined

  if (!channelName) {
    throw new Error('サーバーがサブスクリプションのチャンネルを返しませんでした。')
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
  /** Reports every Pusher *re*-connection, for callers that must re-ask the API. */
  onReconnect: (handler: () => void) => () => void
} {
  const { $echo } = useNuxtApp()
  const backendUrl = useBackendUrl()
  const transport: LighthouseTransport = { echo: $echo, backendUrl }

  const activeHandles = new Set<LighthouseSubscriptionHandle>()
  const reconnectWatchers = new Set<() => void>()

  function stopAll(): void {
    for (const handle of Array.from(activeHandles)) {
      handle.stop()
    }
    activeHandles.clear()

    for (const stopWatching of Array.from(reconnectWatchers)) {
      stopWatching()
    }
    reconnectWatchers.clear()
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

  /**
   * Run `handler` after the socket reconnects, for as long as this composable's
   * scope lives.
   *
   * A caller that only subscribes has no way to notice that the push it is
   * waiting for was aimed at a socket that no longer exists: the channel looks
   * subscribed, the screen looks busy, and nothing arrives. Re-asking the API
   * once the connection is back is what turns that into a delayed result instead
   * of a permanently stuck screen.
   */
  function onReconnect(handler: () => void): () => void {
    const stopWatching = watchPusherReconnects($echo, handler)
    reconnectWatchers.add(stopWatching)

    return () => {
      reconnectWatchers.delete(stopWatching)
      stopWatching()
    }
  }

  if (getCurrentScope()) {
    onScopeDispose(() => {
      stopAll()
    })
  }

  return {
    open,
    stopAll,
    onReconnect,
  }
}
