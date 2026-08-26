import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { createVerifiedAccount, DEFAULT_PASSWORD, grantPlatformRole } from './support/accounts'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'
import { signInThrough } from './support/signin'

/**
 * The Phase 18 gate in a browser: what a super admin can do, and what an operator cannot.
 *
 * The unit suite proves the properties — every admin route has a permission, every critical
 * action leaves a record. What only a run like this shows is that the difference is
 * visible: an operator opening the panel sees the work waiting for them and never reaches
 * the platform's own switches, and a super admin flipping one changes what the API does on
 * the very next request.
 *
 * A flag that turns nothing off would pass a unit test and fail a customer, so the checkout
 * case below flips the switch in the browser and then asks the API what a shopper would be
 * offered.
 */

const ADMIN = process.env.E2E_ADMIN_PANEL_URL ?? 'http://localhost:3002'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

async function signIn(page: Page, email: string): Promise<void> {
  // Retries once through a rate-limit refusal. See tests/e2e/support/signin.ts — the
  // limiter is production-strength on purpose, and a suite this long trips it.
  await signInThrough(page, ADMIN, email)
}

/** An account with a platform role, ready to sign in with. */
async function staffAccount(prefix: string, role: string): Promise<{ email: string, token: string }> {
  const account = await createVerifiedAccount(prefix)

  await grantPlatformRole(account.email, role)

  return { email: account.email, token: account.token }
}

test.describe.configure({ timeout: 420_000 })

test.describe('super admin', () => {
  test('the dashboard leads with the work still waiting', async ({ page }) => {
    const admin = await staffAccount('admin-dashboard', 'super-admin')

    await signIn(page, admin.email)
    await gotoInteractive(page, `${ADMIN}/analytics`)

    await expect(page.getByRole('heading', { name: 'Gösterge paneli' })).toBeVisible()

    /*
     * The queue is above the totals on purpose. A dashboard that folds a pending refund
     * into an average order value has hidden the only number that needed acting on.
     */
    await expect(page.getByRole('heading', { name: 'Sizi bekleyenler' })).toBeVisible()
    await expect(page.getByTestId('queue-transfers')).toBeVisible()
    await expect(page.getByTestId('queue-jobs')).toBeVisible()

    // And each one is a way in, not a number to write down.
    await page.getByTestId('queue-jobs').click()
    await expect(page).toHaveURL(/\/system$/)
  })

  test('the audit trail says who did it and why', async ({ page }) => {
    const admin = await staffAccount('admin-audit', 'super-admin')

    // Something worth reading about: a flag change is a critical action and audited.
    const flags = await page.request.get(`${API}/api/v1/admin/system/flags`, {
      headers: { Authorization: `Bearer ${admin.token}`, Accept: 'application/json' },
    })

    expect(flags.ok(), await flags.text()).toBeTruthy()

    const flag = ((await flags.json()).data as Array<{ id: string, key: string, name: string }>)
      .find(row => row.key === 'checkout.bank-transfer')!

    await page.request.patch(`${API}/api/v1/admin/system/flags/${flag.id}`, {
      headers: { Authorization: `Bearer ${admin.token}`, Accept: 'application/json' },
      data: { key: flag.key, name: flag.name, is_enabled: true, rollout_percentage: 100 },
    })

    await signIn(page, admin.email)
    await gotoInteractive(page, `${ADMIN}/audit`)

    await expect(page.getByRole('heading', { name: 'Denetim kaydı' })).toBeVisible()
    await expect(page.getByTestId('audit-row').first()).toBeVisible()

    // The other half of the screen: what this person may do, so a button they cannot
    // press is explained by a page rather than by a 403.
    await expect(page.getByRole('heading', { name: 'Yetkileriniz' })).toBeVisible()

    // Should always be empty. A route with no permission decision is a Phase 18 failure.
    await expect(page.getByText('Yetki tanımı olmayan uç var.')).toHaveCount(0)
  })

  test('a flag flipped on the screen changes what checkout offers', async ({ page }) => {
    const admin = await staffAccount('admin-flags', 'super-admin')
    const headers = { Authorization: `Bearer ${admin.token}`, Accept: 'application/json' }

    await signIn(page, admin.email)
    await gotoInteractive(page, `${ADMIN}/system`)

    await expect(page.getByRole('heading', { name: 'Sistem' })).toBeVisible()

    const toggle = page.getByTestId('flag-toggle-checkout.bank-transfer')

    await expect(toggle).toBeVisible()

    // Whatever state it is in, this leaves it off.
    if ((await toggle.textContent())?.includes('Kapat')) {
      await toggle.click()
      await expect(page.getByText('kapatıldı.')).toBeVisible()
    }

    // The switch is only real if the API stops offering the method on the next request.
    const methods = await page.request.get(`${API}/api/v1/checkout/methods`, { headers })

    expect(methods.ok(), await methods.text()).toBeTruthy()
    expect(await methods.text()).not.toContain('bank_transfer')

    // Put it back, so the rest of the suite finds the platform as it expects.
    await page.getByTestId('flag-toggle-checkout.bank-transfer').click()
    await expect(page.getByText('açıldı.')).toBeVisible()
  })

  test('an operator works the queues but cannot reach the switches', async ({ page }) => {
    const operator = await staffAccount('admin-operator', 'operator')

    await signIn(page, operator.email)

    // Their own work: what failed overnight is an operational question.
    await gotoInteractive(page, `${ADMIN}/system`)
    await expect(page.getByRole('heading', { name: 'Sistem' })).toBeVisible()

    /*
     * But not the platform's switches. Turning a feature on for everybody is a release
     * decision rather than an operational one, and it is the one power on this screen
     * whose blast radius is the whole platform.
     */
    const flags = await page.request.get(`${API}/api/v1/admin/system/flags`, {
      headers: { Authorization: `Bearer ${operator.token}`, Accept: 'application/json' },
    })

    expect(flags.status()).toBe(403)

    const jobs = await page.request.get(`${API}/api/v1/admin/system/jobs`, {
      headers: { Authorization: `Bearer ${operator.token}`, Accept: 'application/json' },
    })

    expect(jobs.ok(), await jobs.text()).toBeTruthy()
  })

  test('a customer reaches nothing administrative at all', async ({ page }) => {
    const customer = await createVerifiedAccount('admin-outsider')
    const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

    for (const path of ['/api/v1/admin/orders', '/api/v1/admin/audit', '/api/v1/admin/analytics/overview']) {
      const response = await page.request.get(`${API}${path}`, { headers })

      expect(response.status(), path).toBe(403)
    }
  })

  test('the order screen finds an order by its number', async ({ page }) => {
    const admin = await staffAccount('admin-orders', 'super-admin')

    await signIn(page, admin.email)
    await gotoInteractive(page, `${ADMIN}/orders`)

    await expect(page.getByRole('heading', { name: 'Siparişler' })).toBeVisible()

    // Searched by what a caller actually has, and read-only: support moving an order
    // would bypass the transitions the seller portal enforces.
    await page.getByTestId('order-search').fill('RC-BULUNAMAZ-0000')
    await page.getByRole('button', { name: 'Ara' }).click()

    await expect(page.getByTestId('orders-empty')).toBeVisible()
  })
})
