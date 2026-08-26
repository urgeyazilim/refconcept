import { expect } from '@playwright/test'
import type { Page } from '@playwright/test'
import { DEFAULT_PASSWORD } from './accounts'
import { fillStable } from './forms'
import { waitForHydration } from './hydration'

/**
 * Signing in through the browser, without fighting the rate limiter.
 *
 * Login is limited to five attempts a minute **per IP address**, which is right in
 * production and awkward here: a full suite run signs dozens of different accounts in from
 * one machine, and somewhere around the thirtieth journey one of them is refused.
 *
 * The limiter is not the thing being tested in those journeys, and turning it down for the
 * test environment would mean the suite no longer runs against the configuration that
 * ships. So this helper does what a person would: reads the refusal, waits for the window
 * to pass, and tries once more. A second refusal is a real failure and is allowed through.
 *
 * `auth-journey.spec.ts` deliberately does *not* use this — proving the limiter works is
 * its whole job, and a helper that hides a 429 would hide the assertion too.
 */
export async function signInThrough(
  page: Page,
  origin: string,
  email: string,
  expectedUrl: RegExp = /\/(account|)$/,
  password: string = DEFAULT_PASSWORD,
): Promise<void> {
  await page.context().clearCookies()

  const status = await attempt(page, origin, email, password)

  if (status === 429) {
    /*
     * The window is a minute. Waiting it out is slow and honest; retrying immediately
     * would just consume the next attempt and fail for the same reason.
     */
    await page.waitForTimeout(61_000)

    const second = await attempt(page, origin, email, password)

    expect(second, `giriş ikinci denemede de reddedildi (${second})`).toBeLessThan(400)
  } else {
    expect(status, `giriş reddedildi (${status})`).toBeLessThan(400)
  }

  await expect(page).toHaveURL(expectedUrl)
}

/** One attempt, returning the login response status rather than asserting on it. */
async function attempt(page: Page, origin: string, email: string, password: string): Promise<number> {
  await page.goto(`${origin}/auth/login`)
  await waitForHydration(page)

  await fillStable(page, '#email', email)
  await fillStable(page, '#password', password)

  const [response] = await Promise.all([
    page.waitForResponse(res => res.url().includes('/api/v1/auth/login') && res.request().method() === 'POST'),
    page.getByRole('button', { name: 'Giriş yap' }).click(),
  ])

  return response.status()
}
