import { cacheExchange, createClient, fetchExchange, install as installUrql } from '@urql/vue'
import { csrfAwareFetch } from '~/utils/csrf-fetch'

/**
 * Global urql GraphQL client.
 *
 * Installed twice on purpose, because `@urql/vue` reads the client from two
 * different places and neither one sees the other:
 *
 * - `installUrql()` hands it to the Vue app's `provide`, which is where every
 *   composable — `useQuery` on the roadmap screen — looks it up. Providing it
 *   only to Nuxt's global properties is not enough: `useQuery()` resolves
 *   through Vue's injection and fails with "No urql Client was provided"
 *   even though `$urql` is set.
 * - `provide: { urql }` keeps `$urql` available for direct calls
 *   (`useAuth()`, `useAssessment()`), which is the shape the rest of the app
 *   already uses.
 *
 * It is the same client instance in both, so the cache exchange stays one
 * cache.
 *
 * Every request goes through the Sanctum double-submit helper, which keeps the
 * CSRF cookie fresh and the `X-XSRF-TOKEN` header in step with it.
 *
 * Subscriptions are not an exchange here: Lighthouse speaks the Pusher
 * protocol, so `useAssessment()` drives them through Echo instead (README,
 * plan §5).
 */
export default defineNuxtPlugin((nuxtApp) => {
  const config = useRuntimeConfig()
  const backendUrl = String(config.public.backendUrl).replace(/\/$/, '')

  const client = createClient({
    url: `${backendUrl}/graphql`,
    fetchOptions: { credentials: 'include' },
    fetch: (input, init) => csrfAwareFetch(backendUrl, input, init),
    exchanges: [cacheExchange, fetchExchange],
  })

  installUrql(nuxtApp.vueApp, client)

  return { provide: { urql: client } }
})
