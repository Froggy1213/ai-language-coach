/**
 * The Laravel origin this browser should talk to, resolved once per call.
 *
 * `NUXT_PUBLIC_BACKEND_URL` is baked into the image and cannot know which
 * loopback name the learner used to open the SPA; `alignLoopbackHost` closes that
 * gap at runtime. Everything that dials the backend — the urql client, the Echo
 * authorizer, the Lighthouse subscription handshake — resolves through here, so
 * they can never disagree about the origin (which would break the session cookie
 * for one of them only).
 *
 * `runtimeConfig.public` is read on every call rather than captured at module
 * scope: it is a reactive object, and a plugin that captured it early would keep
 * a stale value if the config is replaced.
 */
export function useBackendUrl(): string {
  const config = useRuntimeConfig()
  const configured = String(config.public.backendUrl).replace(/\/$/, '')

  if (!import.meta.client) {
    return configured
  }

  return alignLoopbackHost(configured, window.location.hostname)
}
