import { fileURLToPath } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

/**
 * Component tests for the shared design system.
 *
 * These run against the components rather than through an application, because the
 * decisions worth pinning here are the ones every app inherits: which status gets which
 * colour, whether an error is associated with its field, whether a loading button can be
 * pressed twice. A Playwright journey exercises all of that incidentally and tells you
 * nothing about *why* it broke; a component test names the rule.
 *
 * `happy-dom` rather than jsdom: nothing here needs a layout engine, and the difference is
 * seconds per run.
 */
export default defineConfig({
  plugins: [vue()],
  test: {
    environment: 'happy-dom',
    include: ['src/**/*.spec.ts'],
    setupFiles: ['./vitest.setup.ts'],
    globals: true,
  },
  resolve: {
    alias: {
      '@refconcept/ui': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
})
