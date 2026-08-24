import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end suite for the journeys listed in 15_CRITICAL_E2E_SCENARIOS.md.
 *
 * These run against the *running* stack rather than a mocked one: the whole point is
 * to prove the browser, the Nuxt server, Laravel, PostgreSQL, Redis, the queue worker
 * and the mail transport are actually wired together.
 */
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,

  // One worker: these tests share one database and assert on real mail delivery, so
  // parallel runs would see each other's rows and messages.
  workers: 1,

  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],

  /*
   * Windows dev servers and a cold PHP-FPM are both slow on first touch, and the
   * suite now creates enough accounts in a row to hit the registration throttle —
   * which the helpers wait out rather than disable, because a rate limit that is
   * turned off for tests is a rate limit nothing verifies.
   */
  timeout: 180_000,
  expect: { timeout: 15_000 },

  use: {
    baseURL: process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    locale: 'tr-TR',
    timezoneId: 'Europe/Istanbul',
    actionTimeout: 20_000,
    navigationTimeout: 60_000,
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
