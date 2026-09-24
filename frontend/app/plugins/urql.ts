import { cacheExchange, createClient, fetchExchange } from '@urql/vue'
import { csrfAwareFetch } from '~/utils/csrf-fetch'

/**
 * Global urql GraphQL client ($urql).
 *
 * Every request goes through the Sanctum double-submit helper, which keeps the
 * CSRF cookie fresh and the `X-XSRF-TOKEN` header in step with it.
 *
 * Subscriptions are not an exchange here: Lighthouse speaks the Pusher
 * protocol, so `useAssessment()` drives them through Echo instead (README,
 * plan §5).
 */
export default defineNuxtPlugin(() => {
  const config = useRuntimeConfig()
  const backendUrl = String(config.public.backendUrl).replace(/\/$/, '')

  const client = createClient({
    url: `${backendUrl}/graphql`,
    fetchOptions: { credentials: 'include' },
    fetch: (input, init) => csrfAwareFetch(backendUrl, input, init),
    exchanges: [cacheExchange, fetchExchange],
  })

  return { provide: { urql: client } }
})
