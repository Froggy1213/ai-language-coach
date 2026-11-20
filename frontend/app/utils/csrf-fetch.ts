const CSRF_COOKIE = 'XSRF-TOKEN'

export function readCookie(name: string): string | null {
  if (typeof document === 'undefined') {
    return null
  }

  const match = document.cookie.match(new RegExp(`(^|;\\s*)${name}=([^;]*)`))

  return match ? decodeURIComponent(match[2] ?? '') : null
}

let csrfCookieRequest: Promise<unknown> | null = null

async function ensureCsrfCookie(backendUrl: string, force = false): Promise<void> {
  if (!force && readCookie(CSRF_COOKIE)) {
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

function buildCsrfHeaders(initHeaders?: HeadersInit): Headers {
  const headers = new Headers(initHeaders)
  headers.set('Accept', 'application/json')

  const token = readCookie(CSRF_COOKIE)

  if (token) {
    headers.set('X-XSRF-TOKEN', token)
  }

  return headers
}

/**
 * Sanctum guards the API with a session cookie, which turns every request into
 * a double submit: the browser has to hold the `XSRF-TOKEN` cookie first and
 * send it back URL-decoded in `X-XSRF-TOKEN`. The token is read again on each
 * request instead of being cached — `login` regenerates the session and
 * `logout` destroys it, and a cached header is what turns the next mutation
 * into a 419.
 *
 * On HTTP 419 (expired CSRF token or session rotated in another tab), the
 * `/sanctum/csrf-cookie` handshake is force-refreshed and the request is
 * replayed once with the newly issued token.
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

  const response = await fetch(input, {
    ...init,
    headers: buildCsrfHeaders(init.headers),
    credentials: 'include',
  })

  if (response.status !== 419) {
    return response
  }

  if (
    (typeof Request !== 'undefined' && input instanceof Request && input.bodyUsed)
    || (typeof ReadableStream !== 'undefined' && init.body instanceof ReadableStream)
  ) {
    return response
  }

  await ensureCsrfCookie(backendUrl, true)

  return fetch(input, {
    ...init,
    headers: buildCsrfHeaders(init.headers),
    credentials: 'include',
  })
}
