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
      htmlAttrs: { lang: 'ja' },
      title: 'Kirie — 英語を話す練習',
      meta: [
        {
          name: 'description',
          content: 'AI講師と英語で話して、間違いをその場で振り返る。日本語話者のための英会話練習アプリ。',
        },
        { name: 'theme-color', content: '#101115' },
        { name: 'color-scheme', content: 'dark light' },
      ],
      link: [
        // Latin faces. The Japanese faces are deliberately left to the system
        // stack: Hiragino / Yu Gothic / Noto Sans JP all ship with their
        // platforms, so kanji and kana render natively instead of through a
        // webfont that would be several megabytes and would still lose to the
        // system face on metrics.
        { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
        { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' },
        {
          rel: 'stylesheet',
          href: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Newsreader:ital,opsz,wght@0,6..72,300..500;1,6..72,300..400&family=JetBrains+Mono:wght@400;500&display=swap',
        },
      ],
      script: [
        // Resolve the theme before first paint. Without this the dark canvas
        // flashes white for a frame on every load in light mode.
        {
          innerHTML: `(function(){try{var s=localStorage.getItem('kirie:theme');var light=s?s==='light':window.matchMedia('(prefers-color-scheme: light)').matches;if(light){document.documentElement.classList.add('theme-light');document.documentElement.style.colorScheme='light';}}catch(e){}})();`,
          tagPosition: 'head',
        },
      ],
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
      // Reverb WebSocket server for Echo subscriptions (Pusher protocol on /app/*).
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
