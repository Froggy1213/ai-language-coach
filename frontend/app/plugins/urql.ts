import { cacheExchange, createClient, fetchExchange } from '@urql/vue'

const CSRF_COOKIE = 'XSRF-TOKEN'

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(^|;\\s*)${name}=([^;]*)`))

  return match ? decodeURIComponent(match[2] ?? '') : null
}

/**
 * Global urql GraphQL client ($urql).
 *
 * Sanctum guards `/graphql` with a session cookie, which turns every request
 * into a double submit: the browser has to hold the `XSRF-TOKEN` cookie first
 * and send it back URL-decoded in the `X-XSRF-TOKEN` header. The token is read
 * again on each request instead of being cached — `login` regenerates the
 * session and `logout` destroys it, and a cached header is exactly what turns
 * the next mutation into a 419.
 *
 * Subscriptions are deliberately absent: Lighthouse speaks the Pusher protocol,
 * so the onboarding screen needs an Echo/pusher-js transport, not `graphql-ws`
 * (README, plan §5).
 */
export default defineNuxtPlugin(() => {
  const config = useRuntimeConfig()
  const backendUrl = String(config.public.backendUrl).replace(/\/$/, '')

  let csrfCookieRequest: Promise<unknown> | null = null

  async function ensureCsrfCookie(): Promise<void> {
    if (readCookie(CSRF_COOKIE)) {
      return
    }

    // The app's first query and a burst of parallel ones can all land here at
    // once; they need one cookie between them, not one request each.
    csrfCookieRequest ??= fetch(`${backendUrl}/sanctum/csrf-cookie`, {
      credentials: 'include',
    }).finally(() => {
      csrfCookieRequest = null
    })

    await csrfCookieRequest
  }

  const client = createClient({
    url: `${backendUrl}/graphql`,
    fetchOptions: { credentials: 'include' },
    fetch: async (input, init) => {
      await ensureCsrfCookie()

      const headers = new Headers(init?.headers)
      headers.set('Accept', 'application/json')

      const token = readCookie(CSRF_COOKIE)

      if (token) {
        headers.set('X-XSRF-TOKEN', token)
      }

      return fetch(input, { ...init, headers, credentials: 'include' })
    },
    exchanges: [cacheExchange, fetchExchange],
  })

  return { provide: { urql: client } }
})
