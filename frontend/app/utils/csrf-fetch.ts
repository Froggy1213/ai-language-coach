const CSRF_COOKIE = 'XSRF-TOKEN'

export function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(^|;\\s*)${name}=([^;]*)`))

  return match ? decodeURIComponent(match[2] ?? '') : null
}

let csrfCookieRequest: Promise<unknown> | null = null

async function ensureCsrfCookie(backendUrl: string): Promise<void> {
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

/**
 * Sanctum guards the API with a session cookie, which turns every request into
 * a double submit: the browser has to hold the `XSRF-TOKEN` cookie first and
 * send it back URL-decoded in `X-XSRF-TOKEN`. The token is read again on each
 * request instead of being cached — `login` regenerates the session and
 * `logout` destroys it, and a cached header is what turns the next mutation
 * into a 419.
 *
 * Both the urql client and the subscription handshake go through here, so the
 * two can never drift apart.
 */
export async function csrfAwareFetch(
  backendUrl: string,
  input: RequestInfo | URL,
  init: RequestInit = {},
): Promise<Response> {
  await ensureCsrfCookie(backendUrl)

  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')

  const token = readCookie(CSRF_COOKIE)

  if (token) {
    headers.set('X-XSRF-TOKEN', token)
  }

  return fetch(input, { ...init, headers, credentials: 'include' })
}
