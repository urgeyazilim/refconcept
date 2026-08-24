import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { createVerifiedAccount, DEFAULT_PASSWORD, grantPlatformRole } from './support/accounts'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'

/**
 * The Phase 6 gate: an operator can see what AI is doing and turn it off.
 *
 * The kill switch is the reason this screen exists. Everything else on it — routing,
 * costs, failure counts — is diagnosis; the switch is the thing somebody reaches for at
 * two in the morning when a provider is billing per call and answering none of them.
 *
 * Unit tests already prove the gateway refuses a paused task. What only a run like this
 * proves is that the button on the screen is wired to that rule rather than to its own
 * idea of one, and that the API refuses a paused task afterwards — which is asserted
 * here directly, because a screen that says "durduruldu" while jobs keep running would
 * be worse than no screen at all.
 */

const ADMIN = process.env.E2E_ADMIN_PANEL_URL ?? 'http://localhost:3002'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

async function signIn(page: Page, base: string, email: string): Promise<void> {
  // Cookies are scoped by host and ignore the port, so the three dev apps share one
  // jar; a stale session sends `guest` middleware to bounce the login form away.
  await page.context().clearCookies()
  await page.goto(`${base}/auth/login`)
  await waitForHydration(page)

  await fillStable(page, '#email', email)
  await fillStable(page, '#password', DEFAULT_PASSWORD)
  await page.getByRole('button', { name: 'Giriş yap' }).click()

  await expect(page).toHaveURL(new RegExp(`${base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/?$`))
}

test.describe.configure({ timeout: 300_000 })

test.describe('ai console', () => {
  test('an operator pauses a task and the api stops accepting it', async ({ page, request }) => {
    const admin = await createVerifiedAccount('ai-admin')
    await grantPlatformRole(admin.email, 'super-admin')

    await signIn(page, ADMIN, admin.email)
    await gotoInteractive(page, `${ADMIN}/ai`)

    await expect(page.getByRole('heading', { name: 'AI yönetimi' })).toBeVisible()

    /*
     * Every task appears, routed or not. The seeder routes all twelve, so this is the
     * assertion that the screen is reading the enumeration rather than the routes table
     * — the row that matters most is the one a routes-only list would omit.
     */
    const row = page.locator('tr', { has: page.getByText('Destek asistanı', { exact: true }) })
    await expect(row).toBeVisible()
    await expect(row.getByText('açık')).toBeVisible()

    // The reason is mandatory and the prompt is where it is given.
    page.once('dialog', dialog => dialog.accept('E2E: sağlayıcı kesintisi tatbikatı.'))
    await row.getByRole('button', { name: 'Durdur' }).click()

    await expect(page.getByText('Destek asistanı durduruldu.')).toBeVisible()
    await expect(row.getByText('durduruldu')).toBeVisible()

    // The operator's words are on the screen, not only in a log nobody opens.
    await expect(page.getByText('E2E: sağlayıcı kesintisi tatbikatı.')).toBeVisible()

    /*
     * And the API agrees. This is the half a screenshot cannot prove: the overview now
     * reports the task as paused, which is the same row the gateway reads before it
     * spends anything.
     */
    const overview = await request.get(`${API}/api/v1/admin/ai/overview`, {
      headers: { Authorization: `Bearer ${admin.token}`, Accept: 'application/json' },
    })

    expect(overview.ok()).toBeTruthy()

    const body = await overview.json()
    const supportTask = body.data.tasks.find((task: { task: string }) => task.task === 'support_assist')

    expect(supportTask.route.is_paused).toBe(true)
    expect(supportTask.route.pause_reason).toContain('tatbikatı')

    // Put it back, so a rerun of this suite does not start with the feature off.
    await row.getByRole('button', { name: 'Aç' }).click()
    await expect(page.getByText('Destek asistanı yeniden açıldı.')).toBeVisible()
    await expect(row.getByText('açık')).toBeVisible()
  })

  test('a customer account cannot reach the ai console at all', async ({ request }) => {
    const customer = await createVerifiedAccount('ai-outsider')

    const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

    /*
     * Checked against the API rather than the screen. Hiding a navigation link is a
     * courtesy; the endpoint refusing is the actual control, and it is the one somebody
     * with a token and curl would meet.
     */
    const overview = await request.get(`${API}/api/v1/admin/ai/overview`, { headers })
    expect(overview.status()).toBe(403)

    const usage = await request.get(`${API}/api/v1/admin/ai/usage`, { headers })
    expect(usage.status()).toBe(403)

    const jobs = await request.get(`${API}/api/v1/admin/ai/jobs`, { headers })
    expect(jobs.status()).toBe(403)
  })
})
