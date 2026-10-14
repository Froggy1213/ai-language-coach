/**
 * Loopback aliases: every name a browser on the developer's own machine can use
 * to reach the services this stack publishes on 127.0.0.1.
 *
 * They are *not* interchangeable for the browser's cookie jar. `localhost` and
 * `127.0.0.1` are distinct sites, so a `SameSite=Lax` session cookie issued by
 * one is withheld from a request to the other — and the SPA's whole auth model
 * is that cookie (Sanctum, `supports_credentials`, `X-XSRF-TOKEN`). A page
 * served from `http://localhost:3000` that calls `http://127.0.0.1:8000` gets a
 * 419 on the first mutation and looks like a broken login.
 */
const LOOPBACK_HOSTNAMES = new Set(['localhost', '127.0.0.1', '::1', '[::1]'])

export function isLoopbackHostname(hostname: string): boolean {
  return LOOPBACK_HOSTNAMES.has(hostname.toLowerCase())
}

/**
 * The bare-hostname form of `alignLoopbackHost`, for services configured host by
 * host rather than as a URL (Reverb's `wsHost`, which Echo takes separately from
 * the port).
 */
export function alignLoopbackName(configuredHost: string, pageHostname?: string | null): string {
  if (!pageHostname || !isLoopbackHostname(pageHostname) || !isLoopbackHostname(configuredHost)) {
    return configuredHost
  }

  return pageHostname
}

/**
 * Rewrites a configured service origin so it is reachable from the hostname the
 * page itself was loaded from.
 *
 * The containerised stack publishes the API on `127.0.0.1:8000` while the SPA is
 * browsable at either `127.0.0.1:3000` or `localhost:3000`, and the built image
 * carries exactly one `NUXT_PUBLIC_BACKEND_URL` for both. A build-time value
 * cannot know which name the learner typed, so the value is aligned here at
 * runtime instead of being duplicated per entry point in the proxy config.
 *
 * A no-op unless both sides are loopback aliases: a real hostname (production,
 * staging, a LAN address, a tunnel) is returned untouched, so this cannot move a
 * deployed origin.
 */
export function alignLoopbackHost(configuredUrl: string, pageHostname?: string | null): string {
  if (!pageHostname || !isLoopbackHostname(pageHostname)) {
    return configuredUrl
  }

  let parsed: URL

  try {
    parsed = new URL(configuredUrl)
  } catch {
    // Not an absolute URL — a relative or malformed value is passed through
    // rather than guessed at.
    return configuredUrl
  }

  if (!isLoopbackHostname(parsed.hostname)) {
    return configuredUrl
  }

  parsed.hostname = pageHostname
  // `URL` always renders an origin with a trailing slash; callers concatenate
  // paths onto this value, so the slash has to go.
  return parsed.toString().replace(/\/$/, '')
}
