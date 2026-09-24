import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { csrfAwareFetch } from '~/utils/csrf-fetch'

/**
 * Echo over Reverb ($echo), used for GraphQL subscriptions.
 *
 * Lighthouse speaks the Pusher protocol, so the client has to as well:
 * `graphql-ws` cannot receive its events. Channel authorization is a POST to
 * Lighthouse's auth route, and that route needs the Sanctum session — which
 * means cookies *and* the CSRF header, the same double submit the urql client
 * performs. Echo's default authorizer sends neither, hence the custom one.
 */
export default defineNuxtPlugin(() => {
  const config = useRuntimeConfig()
  const backendUrl = String(config.public.backendUrl).replace(/\/$/, '')

  // pusher-js looks for a global Pusher when it runs in the browser.
  window.Pusher = Pusher

  const echo = new Echo({
    broadcaster: 'reverb',
    key: String(config.public.reverb.appKey),
    wsHost: String(config.public.reverb.host),
    wsPort: Number(config.public.reverb.port),
    wssPort: Number(config.public.reverb.port),
    forceTLS: String(config.public.reverb.scheme) === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${backendUrl}/graphql/subscriptions/auth`,
    authorizer: (channel: { name: string }) => ({
      authorize: async (socketId: string, callback: (error: Error | null, data: unknown) => void) => {
        try {
          const response = await csrfAwareFetch(backendUrl, `${backendUrl}/graphql/subscriptions/auth`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
          })

          if (!response.ok) {
            throw new Error(`Авторизация канала не удалась (${response.status}).`)
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
