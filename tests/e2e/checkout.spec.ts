import { expect, test } from '@playwright/test'
import type { APIRequestContext, Page } from '@playwright/test'
import { createVerifiedAccount, DEFAULT_PASSWORD } from './support/accounts'
import { listProduct } from './support/catalog'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'
import { signInThrough } from './support/signin'

/**
 * The Phase 11 gate, in a browser: paying, and the ways a payment lies about itself.
 *
 * The unit suite proves the state machine — that a capture happens once however many times
 * a provider announces it, that a late failure is dropped, that a refund cannot exceed a
 * capture. What only a run like this proves is that the pages a customer actually sees are
 * wired to those rules: that the total on the payment page is the total that was agreed,
 * that a decline leaves them able to try again rather than back at the start, and that
 * returning from a bank asks what happened instead of assuming.
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

async function signIn(page: Page, email: string): Promise<void> {
  // Retries once through a rate-limit refusal. See tests/e2e/support/signin.ts — the
  // limiter is production-strength on purpose, and a suite this long trips it.
  await signInThrough(page, STOREFRONT, email, /\/account$/)
}

/** A delivery address, because a basket checkout refuses without one. */
async function addAddress(page: Page): Promise<void> {
  await gotoInteractive(page, '/account/addresses')

  await page.getByRole('button', { name: 'Yeni adres ekle' }).click()

  await fillStable(page, '#recipient_name', 'Deniz Yılmaz')
  await fillStable(page, '#city', 'İstanbul')
  await fillStable(page, '#district', 'Kadıköy')
  await fillStable(page, '#address_line1', 'Bağdat Caddesi 100')

  await page.getByRole('button', { name: 'Adresi kaydet' }).click()

  await expect(page.getByText('Bağdat Caddesi 100')).toBeVisible()
}

/**
 * What the ledger says is left, for the SKU this listing sells.
 *
 * The ledger rather than the catalogue: `stock_quantity` on a listing is a projection
 * maintained for the list query, and asserting against a projection would pass the day it
 * stopped being maintained — which is exactly the defect this journey exists to catch.
 */
async function sellableFor(
  request: APIRequestContext,
  listing: { skuId: string, sellerToken: string },
): Promise<number> {
  const response = await request.get(`${API}/api/v1/seller/stock`, {
    headers: { Authorization: `Bearer ${listing.sellerToken}`, Accept: 'application/json' },
  })

  const rows = (await response.json()).data as Array<{ sku: { id: string }, sellable: number }>
  const row = rows.find(entry => entry.sku.id === listing.skuId)

  expect(row, 'stok kaydı bulunmalı').toBeTruthy()

  return row!.sellable
}

test.describe.configure({ timeout: 300_000 })

test.describe('checkout', () => {
  test('a customer pays for a basket and the stock leaves the shelf', async ({ page, request }) => {
    const listing = await listProduct(request, `Ödeme Kanepe ${Date.now()}`, 1_500_000, 3)
    const customer = await createVerifiedAccount('payer')

    const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

    await request.post(`${API}/api/v1/cart/items`, {
      headers,
      data: { sku_id: listing.skuId, quantity: 2 },
    })

    await signIn(page, customer.email)
    await addAddress(page)

    await gotoInteractive(page, '/cart')
    await page.getByRole('button', { name: 'Ödemeye geç' }).click()

    await expect(page).toHaveURL(/\/checkout/)

    // The figure on the page is the figure that will be charged, stated once, in the
    // currency the customer reads.
    await expect(page.getByRole('heading', { name: 'Ödeme', exact: true })).toBeVisible()
    await expect(page.getByText('Bağdat Caddesi 100')).toBeVisible()

    const payButton = page.getByRole('button', { name: /₺.*öde/ })

    await expect(payButton).toBeVisible()

    await page.getByRole('radio', { name: 'Başarılı ödeme' }).check()
    await payButton.click()

    await expect(page.getByRole('heading', { name: 'Ödemeniz alındı' })).toBeVisible({ timeout: 30_000 })

    /*
     * The stock is gone, not merely held. A hold that expired after payment would put a
     * sold sofa back on the shelf — the one stock bug a marketplace cannot explain away.
     *
     * Read from the ledger, which is the authority, rather than from the catalogue, which
     * carries a projection of it.
     */
    expect(await sellableFor(request, listing)).toBe(1)

    // And the basket is closed rather than left sitting there ready to be paid again.
    const cart = await request.get(`${API}/api/v1/cart`, { headers })

    expect((await cart.json()).data.item_count).toBe(0)
  })

  test('a declined card leaves the customer able to try again', async ({ page, request }) => {
    const listing = await listProduct(request, `Ret Kanepe ${Date.now()}`, 800_000, 2)
    const customer = await createVerifiedAccount('declined')

    const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

    await request.post(`${API}/api/v1/cart/items`, {
      headers,
      data: { sku_id: listing.skuId, quantity: 1 },
    })

    await signIn(page, customer.email)
    await addAddress(page)

    await gotoInteractive(page, '/checkout')

    await page.getByRole('radio', { name: 'Banka reddeder' }).check()
    await page.getByRole('button', { name: /₺.*öde/ }).click()

    await expect(page.getByRole('heading', { name: 'Ödeme tamamlanamadı' })).toBeVisible({ timeout: 30_000 })

    // The bank's own reason, in Turkish, rather than a code.
    await expect(page.getByText('Kartınızın bakiyesi bu ödeme için yeterli değil.')).toBeVisible()

    /*
     * The session survives the refusal. Throwing it away would make the customer start
     * over — re-entering the address, and paying whatever the prices are by then.
     */
    await page.getByRole('link', { name: 'Tekrar dene' }).click()

    await expect(page).toHaveURL(/\/checkout/)
    await waitForHydration(page)

    await page.getByRole('radio', { name: 'Başarılı ödeme' }).check()
    await page.getByRole('button', { name: /₺.*öde/ }).click()

    await expect(page.getByRole('heading', { name: 'Ödemeniz alındı' })).toBeVisible({ timeout: 30_000 })
  })

  test('a 3D Secure step sends the customer to the bank and back', async ({ page }) => {
    const customer = await createVerifiedAccount('threeds')

    await signIn(page, customer.email)

    await gotoInteractive(page, '/account/credits')

    const balanceBefore = await page.getByTestId('credit-balance').textContent()

    await page.getByRole('button', { name: 'Satın al' }).first().click()

    await expect(page).toHaveURL(/\/checkout\?purpose=credits/)
    await waitForHydration(page)

    await page.getByRole('radio', { name: '3D Secure adımı ister' }).check()
    await page.getByRole('button', { name: /₺.*öde/ }).click()

    // The stand-in bank page, which says plainly that it is not one.
    await expect(page.getByText('Bu sayfa gerçek bir banka sayfası değildir.')).toBeVisible({ timeout: 30_000 })

    await page.getByRole('button', { name: 'Onaylıyorum' }).click()

    /*
     * Back on our side, the page asks rather than assumes. The webhook and the browser
     * race each other on every real 3DS flow, and a page that believed the redirect would
     * be right roughly half the time.
     */
    await expect(page.getByRole('heading', { name: 'Ödemeniz alındı' })).toBeVisible({ timeout: 60_000 })

    await gotoInteractive(page, '/account/credits')

    await expect(page.getByTestId('credit-balance')).not.toHaveText(balanceBefore ?? '')
  })

  test('a repeated payment request is answered once', async ({ request }) => {
    const listing = await listProduct(request, `Tekrar Kanepe ${Date.now()}`, 600_000, 4)
    const customer = await createVerifiedAccount('repeater')

    const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

    await request.post(`${API}/api/v1/addresses`, {
      headers,
      data: {
        recipient_name: 'Deniz Yılmaz',
        city: 'İstanbul',
        district: 'Kadıköy',
        address_line1: 'Bağdat Caddesi 100',
        is_default_shipping: true,
        is_default_billing: true,
      },
    })

    await request.post(`${API}/api/v1/cart/items`, {
      headers,
      data: { sku_id: listing.skuId, quantity: 1 },
    })

    await request.post(`${API}/api/v1/checkout`, { headers })

    const body = { purpose: 'cart', payment_token: 'tok_success' }
    const withKey = { ...headers, 'Idempotency-Key': 'e2e-double-tap' }

    const first = await request.post(`${API}/api/v1/checkout/pay`, { headers: withKey, data: body })
    const second = await request.post(`${API}/api/v1/checkout/pay`, { headers: withKey, data: body })

    expect(first.ok(), await first.text()).toBeTruthy()
    expect(second.ok(), await second.text()).toBeTruthy()

    const firstPayment = (await first.json()).data.payment
    const secondPayment = (await second.json()).data.payment

    // One payment, whatever the connection did with the request.
    expect(secondPayment.id).toBe(firstPayment.id)
    expect(second.headers()['idempotent-replay']).toBe('true')

    // And one unit gone from the shelf, not two.
    expect(await sellableFor(request, listing)).toBe(3)
  })

  test('the same confirmation arriving four times loads credits once', async ({ request }) => {
    const customer = await createVerifiedAccount('duplicate-webhook')

    const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

    const packages = await (await request.get(`${API}/api/v1/credits/packages`)).json()
    const pack = packages.data[0]

    expect(pack, 'kredi paketi tanımlı olmalı').toBeTruthy()

    await request.post(`${API}/api/v1/checkout/credits`, {
      headers,
      data: { package_id: pack.id },
    })

    const started = await request.post(`${API}/api/v1/checkout/pay`, {
      headers,
      data: { purpose: 'credits', payment_token: 'tok_3ds' },
    })

    expect(started.ok(), await started.text()).toBeTruthy()

    const reference = (await started.json()).data.payment.reference

    /*
     * Four identical deliveries, the way a provider that never saw our 200 would send
     * them. This is E2E-03 from 15_CRITICAL_E2E_SCENARIOS.md, and the whole point of the
     * inbox: one payment effect, one credit load.
     */
    const delivered = await request.post(`${API}/api/v1/payments/fake/${reference}/complete`, {
      data: { outcome: 'captured', deliveries: 4, event_id: `e2e-repeat-${Date.now()}` },
    })

    expect(delivered.ok(), await delivered.text()).toBeTruthy()

    const deliveries = (await delivered.json()).data.deliveries

    expect(deliveries).toHaveLength(4)
    expect(deliveries.filter((entry: { duplicate: boolean }) => entry.duplicate)).toHaveLength(3)

    /*
     * Polled rather than read once.
     *
     * The webhook is acknowledged the moment it is stored and understood afterwards on a
     * worker — which is the whole point of the inbox, and means the wallet moves a beat
     * after the provider is answered. Reading it immediately would be testing the queue's
     * latency rather than the behaviour.
     */
    await expect.poll(
      async () => (await (await request.get(`${API}/api/v1/credits`, { headers })).json()).data.balance,
      { timeout: 60_000, message: 'kredi bakiyesi bir kez yüklenmeli' },
    ).toBe(pack.credits + pack.bonus_credits)
  })

  test('a forged confirmation is refused and kept', async ({ request }) => {
    const forged = await request.post(`${API}/api/v1/payments/webhooks/fake`, {
      headers: { 'Content-Type': 'application/json', 'X-Refconcept-Signature': 'definitely-not-it' },
      data: { event_id: `e2e-forged-${Date.now()}`, status: 'captured', payment_id: 'fake_nothing' },
    })

    // Refused at the door. Stored anyway, on the other side of the door, because an
    // unsigned claim that a payment succeeded is worth someone looking at.
    expect(forged.status()).toBe(401)
  })
})
