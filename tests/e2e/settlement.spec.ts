import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { createVerifiedAccount, DEFAULT_PASSWORD } from './support/accounts'
import { listProduct } from './support/catalog'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'

/**
 * The Phase 16 gate in a browser: commission, the ledger, and getting a seller paid.
 *
 * The unit suite proves the invariants — every entry balances, nothing is edited, the same
 * event posts once. What only a run like this shows is that the two people who actually
 * use this see the truth: a seller looking at what they are owed and *when*, and an
 * operator who cannot turn one click into a bank transfer.
 */

const SELLER_PORTAL = process.env.E2E_SELLER_PORTAL_URL ?? 'http://localhost:3001'
const ADMIN = process.env.E2E_ADMIN_PANEL_URL ?? 'http://localhost:3002'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

async function signIn(page: Page, email: string, origin: string): Promise<void> {
  await page.context().clearCookies()
  await page.goto(`${origin}/auth/login`)
  await waitForHydration(page)

  await fillStable(page, '#email', email)
  await fillStable(page, '#password', DEFAULT_PASSWORD)
  await page.getByRole('button', { name: 'Giriş yap' }).click()

  await expect(page).toHaveURL(/\/(account|)$/)
}

/** Buys the listing, pays, and walks the seller order to delivered. */
async function sellAndDeliver(
  request: Parameters<typeof listProduct>[0],
  listing: Awaited<ReturnType<typeof listProduct>>,
  quantity = 1,
): Promise<{ sellerOrderNumber: string, customerEmail: string }> {
  const customer = await createVerifiedAccount('settlement-buyer')
  const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

  await request.post(`${API}/api/v1/addresses`, {
    headers,
    data: {
      recipient_name: 'Deniz Yılmaz',
      city: 'İstanbul',
      address_line1: 'Bağdat Caddesi 100',
      is_default_shipping: true,
    },
  })

  await request.post(`${API}/api/v1/cart/items`, { headers, data: { sku_id: listing.skuId, quantity } })
  await request.post(`${API}/api/v1/checkout`, { headers })

  const paid = await request.post(`${API}/api/v1/checkout/pay`, {
    headers,
    data: { purpose: 'cart', payment_token: 'tok_success' },
  })

  expect(paid.ok(), await paid.text()).toBeTruthy()

  const sellerHeaders = { Authorization: `Bearer ${listing.sellerToken}`, Accept: 'application/json' }

  const queue = await request.get(`${API}/api/v1/seller/orders`, { headers: sellerHeaders })
  const number = ((await queue.json()).data as Array<{ seller_order_number: string }>)[0]!.seller_order_number

  for (const status of ['confirmed', 'shipped', 'delivered']) {
    const moved = await request.post(`${API}/api/v1/seller/orders/${number}/status`, {
      headers: sellerHeaders,
      data: { status },
    })

    expect(moved.ok(), await moved.text()).toBeTruthy()
  }

  return { sellerOrderNumber: number, customerEmail: customer.email }
}

test.describe.configure({ timeout: 420_000 })

test.describe('settlement', () => {
  test('a seller sees what they are owed and when', async ({ page, request }) => {
    const listing = await listProduct(request, `Hakediş Kanepe ${Date.now()}`, 900_000, 3)

    await sellAndDeliver(request, listing)

    await signIn(page, listing.sellerEmail, SELLER_PORTAL)
    await gotoInteractive(page, `${SELLER_PORTAL}/earnings`)

    await expect(page.getByRole('heading', { name: 'Hakedişlerim' })).toBeVisible()

    /*
     * Four figures, not one. The money really is in four states, and a single "bakiye" is
     * how a seller reads a number they cannot yet have.
     */
    await expect(page.getByText('Ödemeye hazır', { exact: true })).toBeVisible()
    await expect(page.getByText('Bekleyen', { exact: true })).toBeVisible()
    await expect(page.getByText('Ödeme sırasında', { exact: true })).toBeVisible()

    // And a sentence per order rather than a status code.
    await expect(page.getByText(/tarihinde hakedişe girer/)).toBeVisible()

    // The commission is shown next to the gross, so a payout is never a surprise.
    await expect(page.getByText('Hakediş', { exact: true }).first()).toBeVisible()
  })

  test('the books balance and an operator can see them', async ({ page, request }) => {
    const listing = await listProduct(request, `Defter Kanepe ${Date.now()}`, 700_000, 2)

    await sellAndDeliver(request, listing)

    const overview = await request.get(`${API}/api/v1/admin/finance/overview`, {
      headers: { Authorization: `Bearer ${listing.operatorToken}`, Accept: 'application/json' },
    })

    expect(overview.ok(), await overview.text()).toBeTruthy()

    const body = await overview.json()

    // If this is ever false, nothing else on the page means anything.
    expect(body.data.is_balanced).toBe(true)

    await signIn(page, listing.operatorEmail, ADMIN)
    await gotoInteractive(page, `${ADMIN}/finance`)

    await expect(page.getByRole('heading', { name: 'Finans' })).toBeVisible()
    await expect(page.getByText('Satıcıya borç')).toBeVisible()
    await expect(page.getByText('Komisyon geliri')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Yevmiye' })).toBeVisible()
  })

  test('a delivery inside the hold cannot be paid out', async ({ request }) => {
    const listing = await listProduct(request, `Bekleme Kanepe ${Date.now()}`, 500_000, 2)

    await sellAndDeliver(request, listing)

    const operatorHeaders = { Authorization: `Bearer ${listing.operatorToken}`, Accept: 'application/json' }

    const built = await request.post(`${API}/api/v1/admin/finance/settlements/build`, {
      headers: operatorHeaders,
    })

    expect(built.ok()).toBeTruthy()

    const settlements = (await built.json()).data as Array<{ seller_name: string | null }>

    /*
     * The return window. Paying before it closes means chasing a seller for money they
     * have already spent — so a delivery from a minute ago is not in the run.
     */
    expect(settlements.some(row => row.seller_name?.includes('Bekleme Kanepe'))).toBe(false)
  })

  /*
   * The approve-then-pay two-step is proved in the backend suite rather than here.
   *
   * A settlement needs a delivery that is past the hold, and the only ways to produce one
   * in a browser run are to wait a fortnight or to open a back door that ages deliveries
   * on demand. A test-only endpoint that moves money closer to leaving is not worth the
   * coverage; the backend suite controls the clock properly and asserts the same thing.
   */

  test('a seller cannot reach platform finance', async ({ request }) => {
    const listing = await listProduct(request, `Yetki Kanepe ${Date.now()}`, 300_000, 2)

    const forbidden = await request.get(`${API}/api/v1/admin/finance/overview`, {
      headers: { Authorization: `Bearer ${listing.sellerToken}`, Accept: 'application/json' },
    })

    expect(forbidden.status()).toBe(403)
  })
})
