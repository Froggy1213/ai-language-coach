import tailwindcss from '@tailwindcss/vite'

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },
  css: ['~/assets/css/main.css'],
  vite: {
    plugins: [tailwindcss()],
  },
  runtimeConfig: {
    public: {
      // Laravel backend. Override with NUXT_PUBLIC_BACKEND_URL when needed.
      // In production this is same-origin behind the ALB.
      backendUrl: 'http://localhost:8000',
    },
  },
})
