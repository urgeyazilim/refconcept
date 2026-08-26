import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { createVerifiedAccount, DEFAULT_PASSWORD, grantPlatformRole } from './support/accounts'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'
import { signInThrough } from './support/signin'

/**
 * The Phase 7 gate: a customer's credits behave like money.
 *
 * Unit tests already prove the ledger's invariants — a balance that cannot go negative, a
 * hold that settles once, a retry that does not charge twice. What only a run like this
 * proves is that the screen a customer actually looks at is reading the same ledger, and
 * that a code redeemed in a browser cannot be redeemed again from the same browser.
 *
 * The second test is the one worth having: it checks that the *same* code refuses a second
 * time with the message a person can act on, rather than the generic refusal reserved for
 * codes somebody is guessing at.
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'
const ADMIN = process.env.E2E_ADMIN_PANEL_URL ?? 'http://localhost:3002'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

async function signIn(page: Page, base: string, email: string): Promise<void> {
  // Retries once through a rate-limit refusal. See tests/e2e/support/signin.ts — the
  // limiter is production-strength on purpose, and a suite this long trips it.
  await signInThrough(page, base, email)
}

test.describe.configure({ timeout: 300_000 })

test.describe('credit economy', () => {
  // One operator for the whole file, for the reason given in design-generation.spec.ts:
  // every avoidable registration is a minute of the shared rate limit.
  let admin: Awaited<ReturnType<typeof createVerifiedAccount>>

  test.beforeAll(async () => {
    admin = await createVerifiedAccount('credit-admin')
    await grantPlatformRole(admin.email, 'super-admin')
  })

  test('a customer redeems a code, sees the balance move and cannot claim it twice', async ({ page, request }) => {
    const code = `E2E${Date.now()}`

    // The campaign is created through the API a member of staff would use, not inserted
    // behind its back — the per-user limit being enforced is part of what is under test.
    const created = await request.post(`${API}/api/v1/admin/credits/promotions`, {
      headers: { Authorization: `Bearer ${admin.token}`, Accept: 'application/json' },
      data: {
        code,
        name: 'E2E kampanyası',
        credits: 40,
        validity_days: 30,
        max_per_user: 1,
      },
    })

    expect(created.ok()).toBeTruthy()

    const customer = await createVerifiedAccount('credit-customer')

    await signIn(page, STOREFRONT, customer.email)
    await gotoInteractive(page, `${STOREFRONT}/account/credits`)

    await expect(page.getByRole('heading', { name: 'Kredilerim' })).toBeVisible()

    const available = page.locator('p', { hasText: 'Kullanılabilir' }).locator('..')
    await expect(available).toContainText('0')

    await fillStable(page, 'input[placeholder="HOSGELDIN"]', code)
    await page.getByRole('button', { name: 'Kullan' }).click()

    await expect(page.getByText('40 kredi hesabınıza tanımlandı.')).toBeVisible()

    // The balance, the expiry warning and the statement all move together, because all
    // three are reading the one ledger.
    await expect(available).toContainText('40')
    await expect(page.getByText('40 kredinin süresi yakında doluyor')).toBeVisible()
    await expect(page.getByText('E2E kampanyası').first()).toBeVisible()

    // A second attempt from the same account says so plainly — the person asking has
    // already proved they know the code, so nothing is disclosed by being specific.
    await fillStable(page, 'input[placeholder="HOSGELDIN"]', code)
    await page.getByRole('button', { name: 'Kullan' }).click()

    await expect(page.getByText('Bu kodu zaten kullandınız.')).toBeVisible()
    await expect(available).toContainText('40')

    /*
     * And the API agrees with the screen. This is the half a screenshot cannot prove: one
     * grant on the ledger, not two, however many times the button was pressed.
     */
    const wallet = await request.get(`${API}/api/v1/credits`, {
      headers: { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' },
    })

    const body = await wallet.json()

    expect(body.data.balance).toBe(40)
    expect(body.data.available).toBe(40)
    expect(body.data.lifetime.granted).toBe(40)
    expect(body.data.lifetime.purchased).toBe(0)
  })

  test('staff correct a balance with a reason and the customer sees why', async ({ page, request }) => {
    const customer = await createVerifiedAccount('credit-fixed')

    const headers = { Authorization: `Bearer ${admin.token}`, Accept: 'application/json' }

    // A correction without a reason is refused. This is the one movement that is
    // indistinguishable from theft without a record of who made it and why.
    const unexplained = await request.post(
      `${API}/api/v1/admin/credits/wallets/${await userIdFor(request, customer.token)}/adjust`,
      { headers, data: { delta: 60, reason: 'kısa' } },
    )

    expect(unexplained.status()).toBe(422)

    const adjusted = await request.post(
      `${API}/api/v1/admin/credits/wallets/${await userIdFor(request, customer.token)}/adjust`,
      { headers, data: { delta: 60, reason: 'Kesintiden etkilenen müşteriye telafi.' } },
    )

    expect(adjusted.ok()).toBeTruthy()

    await signIn(page, STOREFRONT, customer.email)
    await gotoInteractive(page, `${STOREFRONT}/account/credits`)

    // The reason reaches the customer's own statement, not just an internal log.
    await expect(page.getByText('Kesintiden etkilenen müşteriye telafi.')).toBeVisible()
    await expect(page.getByText('+60')).toBeVisible()
  })

  test('a customer cannot reach credit administration', async ({ request }) => {
    const customer = await createVerifiedAccount('credit-outsider')

    const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

    // Checked against the API rather than the screen: hiding a link is a courtesy, the
    // endpoint refusing is the control.
    for (const path of ['/api/v1/admin/credits/packages', '/api/v1/admin/credits/promotions']) {
      const response = await request.get(`${API}${path}`, { headers })
      expect(response.status()).toBe(403)
    }
  })

  test('an operator sees packages and can close one', async ({ page }) => {
    await signIn(page, ADMIN, admin.email)
    await gotoInteractive(page, `${ADMIN}/credits`)

    await expect(page.getByRole('heading', { name: 'Kredi yönetimi' })).toBeVisible()

    // The seeded packages are there, priced in the currency the API sent rather than one
    // the page assumed.
    const row = page.locator('tr', { has: page.getByText('Başlangıç', { exact: true }) })
    await expect(row).toBeVisible()
    await expect(row.getByText('satışta')).toBeVisible()

    await row.getByRole('button', { name: 'Kapat' }).click()
    await expect(page.getByText('Kapatıldı.')).toBeVisible()
    await expect(row.getByText('kapalı')).toBeVisible()

    // Put it back, so a rerun does not start with the shop window half empty.
    await row.getByRole('button', { name: 'Aç' }).click()
    await expect(row.getByText('satışta')).toBeVisible()
  })
})

/** The signed-in user's own id, which the admin routes address a wallet by. */
async function userIdFor(request: import('@playwright/test').APIRequestContext, token: string): Promise<string> {
  const response = await request.get(`${API}/api/v1/auth/me`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  })

  const body = await response.json()

  return body.data.id
}
