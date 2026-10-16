import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import type { ChannelAuthorizationCallback } from 'pusher-js'
import { csrfAwareFetch } from '~/utils/csrf-fetch'

declare global {
  interface Window {
    Pusher: typeof Pusher
  }
}

/**
 * Echo over Reverb (`$echo`), used for GraphQL subscriptions.
 *
 * Lighthouse speaks the Pusher protocol, so the client has to as well:
 * `graphql-ws` cannot receive its events. Channel authorization is a POST to
 * Lighthouse's auth route, and that route needs the Sanctum session — which
 * means cookies *and* the CSRF header, the same double submit the urql client
 * performs. Echo's default authorizer sends neither, hence the custom one.
 *
 * **The socket opens here, at bootstrap, and that is accepted rather than
 * overlooked.** `new Echo()` connects inside its constructor and pusher-js has no
 * "build the client but do not dial" option (its `enabled` key controls
 * *transports*, not the connection — verified against pusher-js 8.6.0). Passing a
 * stub `client` and swapping in the real Pusher on first use would work, but it
 * means hand-forging an object that Echo only ever touches through `any`, and
 * getting one property wrong breaks subscriptions in a way no type checker would
 * catch. The cost of the honest version is one connection and — only when Reverb
 * is not running, which is not a production state — one console error per page
 * load. That is the better trade.
 *
 * The host is aligned with the page for the same reason the API origin is: the
 * image ships one configured name (`127.0.0.1` in compose, `localhost` on the
 * host) while the learner can arrive through either, and a socket dialled at the
 * other name is a cross-origin connection whose authorizer would then post to a
 * third.
 */
export default defineNuxtPlugin(() => {
  const config = useRuntimeConfig()
  const backendUrl = useBackendUrl()
  const reverbHost = alignLoopbackName(
    String(config.public.reverb.host),
    import.meta.client ? window.location.hostname : null,
  )

  // pusher-js looks for a global Pusher when it runs in the browser.
  if (typeof window !== 'undefined') {
    window.Pusher = Pusher
  }

  const echo = new Echo({
    broadcaster: 'reverb',
    key: String(config.public.reverb.appKey),
    wsHost: reverbHost,
    wsPort: Number(config.public.reverb.port),
    wssPort: Number(config.public.reverb.port),
    forceTLS: String(config.public.reverb.scheme) === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${backendUrl}/graphql/subscriptions/auth`,
    authorizer: (channel: { name: string }) => ({
      authorize: async (socketId: string, callback: ChannelAuthorizationCallback) => {
        try {
          const response = await csrfAwareFetch(backendUrl, `${backendUrl}/graphql/subscriptions/auth`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
          })

          if (!response.ok) {
            throw new Error(`チャンネルの認証に失敗しました（${response.status}）。`)
          }

          callback(null, await response.json())
        } catch (error) {
          callback(error as Error, null)
        }
      },
    }),
  })

  return { provide: { echo } }
})
