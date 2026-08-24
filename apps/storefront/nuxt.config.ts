import { fileURLToPath } from 'node:url'
import tailwindcss from '@tailwindcss/vite'

// RefConcept Storefront — customer-facing web app.
// Design language and screen structure: 21_DESIGN_SYSTEM_UI_SPEC.md / 22_SCREEN_BLUEPRINTS.
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  modules: ['@nuxt/eslint'],

  css: ['~/assets/css/main.css'],


  // Shared API/auth composables live in @refconcept/ui so all three apps talk to the
  // backend the same way; duplicating them per app is how error handling drifts.
  imports: {
    dirs: [fileURLToPath(new URL('../../packages/ui/src/runtime', import.meta.url))],
  },

  // Shared design-system components (@refconcept/ui) are auto-imported alongside local ones.
  components: [
    { path: '~/components', pathPrefix: false },
    {
      path: fileURLToPath(new URL('../../packages/ui/src/components', import.meta.url)),
      pathPrefix: false,
    },
  ],

  vite: {
    plugins: [tailwindcss()],
  },

  typescript: {
    strict: true,
    typeCheck: false, // run explicitly via `npm run typecheck` / CI
  },

  runtimeConfig: {
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:58000',
      appName: 'RefConcept',
      // Development convenience: a link to the local mail catcher so a developer can
      // open the verification e-mail without leaving the flow. Empty in production.
      mailUrl: process.env.NUXT_PUBLIC_MAIL_URL || 'http://localhost:58025',
    },
  },

  app: {
    head: {
      htmlAttrs: { lang: 'tr' },
      titleTemplate: '%s · RefConcept',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'theme-color', content: '#111111' },
      ],
      link: [
        { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
        { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' },
        {
          rel: 'stylesheet',
          href: 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap',
        },
      ],
    },
  },

  nitro: {
    compressPublicAssets: true,
  },
})
