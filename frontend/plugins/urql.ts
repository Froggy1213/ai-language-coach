import { createClient, cacheExchange, fetchExchange, subscriptionExchange } from '@urql/vue'
import { createClient as createWsClient } from 'graphql-ws'

/**
 * Global urql GraphQL client ($urql).
 *
 * Queries/mutations go to the Lighthouse /graphql endpoint over HTTP with
 * credentials included (Sanctum SPA cookie auth). Subscriptions use the same
 * endpoint over WebSocket — the transport (Lighthouse+pusher→Reverb vs the
 * polling fallback) is decided in the October spike, so the URL stays
 * config-driven here.
 */
export default defineNuxtPlugin((nuxtApp) => {
  const config = useRuntimeConfig()
  const httpUrl = `${config.public.backendUrl}/graphql`
  const wsUrl = httpUrl.replace(/^http/, 'ws')

  const wsClient = createWsClient({ url: wsUrl })

  const client = createClient({
    url: httpUrl,
    fetchOptions: { credentials: 'include' },
    exchanges: [
      cacheExchange,
      fetchExchange,
      subscriptionExchange({
        forwardSubscription: (operation) => ({
          subscribe: (sink) => ({ unsubscribe: wsClient.subscribe(operation, sink) }),
        }),
      }),
    ],
  })

  return { provide: { urql: client } }
})
