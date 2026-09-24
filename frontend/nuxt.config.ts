import tailwindcss from '@tailwindcss/vite'

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },
  // SPA (plan §1): the Sanctum session cookie lives in the browser, so there is
  // nothing meaningful to render on the server — and no session to render with.
  ssr: false,
  css: ['~/assets/css/main.css'],
  app: {
    head: {
      htmlAttrs: { lang: 'ru' },
      title: 'AI Language Coach',
    },
  },
  vite: {
    plugins: [tailwindcss()],
  },
  runtimeConfig: {
    public: {
      // Laravel backend. Override with NUXT_PUBLIC_BACKEND_URL when needed.
      // In production this is same-origin behind the ALB.
      backendUrl: 'http://localhost:8000',
      // Reverb WebSocket server for graphql-ws subscriptions / Echo.
      // Overridden by NUXT_PUBLIC_REVERB_APP_KEY / _HOST / _PORT / _SCHEME.
      // Env names mirror the config path (snake_case of public.reverb.appKey).
      reverb: {
        appKey: '',
        host: 'localhost',
        port: 8080,
        scheme: 'http',
      },
      // Self-hosted LiveKit; direct WebRTC from the browser.
      // Overridden by NUXT_PUBLIC_LIVEKIT_URL.
      livekit: {
        url: 'ws://localhost:7880',
      },
    },
  },
})
