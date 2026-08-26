import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { createVerifiedAccount, DEFAULT_PASSWORD } from './support/accounts'
import { listProduct } from './support/catalog'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'
import { signInThrough } from './support/signin'

/**
 * The Phase 14 gate, in a browser: paying by transfer, and the two ways it goes wrong.
 *
 * The unit suite proves the arithmetic — that a shortfall releases nothing, that a second
 * confirmation is refused. What only a run like this shows is that the reference a
 * customer is asked to type is the reference finance sees in their queue, and that the
 * screen where somebody confirms money has arrived tells them what they are about to do
 * before they do it.
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

async function signIn(page: Page, email: string): Promise<void> {
  // Retries once through a rate-limit refusal. See tests/e2e/support/signin.ts — the
  // limiter is production-strength on purpose, and a suite this long trips it.
  await signInThrough(page, STOREFRONT, email, /\/account$/)
}

test.describe.configure({ timeout: 300_000 })

test.describe('bank transfer', () => {
  test('a customer is given a reference and finance settles it', async ({ page, request }) => {
    const listing = await listProduct(request, `Havale Kanepe ${Date.now()}`, 700_000, 3)
    const customer = await createVerifiedAccount('transfer-payer')

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

    await signIn(page, customer.email)
    await gotoInteractive(page, '/checkout')

    await page.getByRole('radio', { name: 'Havale / EFT' }).check()

    // What the method costs in time, said before the customer commits to it.
    await expect(page.getByText('iki gün boyunca sizin için ayrılır')).toBeVisible()

    await page.getByRole('button', { name: 'Havale bilgilerini al' }).click()

    await expect(page).toHaveURL(/\/checkout\/transfer\//, { timeout: 30_000 })

    /*
     * The reference is the whole mechanism: a transfer arrives at the bank as a line on a
     * statement, and this code is the only thing tying it to an order.
     */
    const referenceNode = page.getByTestId('transfer-reference')

    await expect(referenceNode).toBeVisible()

    const reference = (await referenceNode.textContent())?.trim() ?? ''

    expect(reference).toMatch(/^RC-/)
    expect(reference).not.toMatch(/[01OIL]/)

    await expect(page.getByText('yalnızca bu kodu')).toBeVisible()
    await expect(page.getByText('TR33')).toBeVisible()

    await page.getByRole('button', { name: 'Gönderdim' }).click()
    await expect(page.getByText('Bildiriminiz alındı')).toBeVisible()

    // --- finance ---------------------------------------------------------------
    const queue = await request.get(`${API}/api/v1/admin/payments/transfers`, {
      headers: { Authorization: `Bearer ${listing.operatorToken}`, Accept: 'application/json' },
    })

    expect(queue.ok(), await queue.text()).toBeTruthy()

    const rows = (await queue.json()).data as Array<{ id: string, reference: string }>
    const row = rows.find(entry => entry.reference === reference)

    expect(row, 'havale finans kuyruğunda görünmeli').toBeTruthy()

    const confirmed = await request.post(
      `${API}/api/v1/admin/payments/transfers/${row!.id}/confirm`,
      {
        headers: { Authorization: `Bearer ${listing.operatorToken}`, Accept: 'application/json' },
        data: { received_minor: 700_000, value_date: '2026-08-26' },
      },
    )

    expect(confirmed.ok(), await confirmed.text()).toBeTruthy()
    expect((await confirmed.json()).data.status).toBe('confirmed')

    // The customer's page now says so, and the basket is closed.
    await page.reload()
    await waitForHydration(page)

    await expect(page.getByText('Ödemeniz alındı.')).toBeVisible()

    const cart = await request.get(`${API}/api/v1/cart`, { headers })

    expect((await cart.json()).data.item_count).toBe(0)
  })

  test('a short payment releases nothing and says what is still owed', async ({ page, request }) => {
    const listing = await listProduct(request, `Eksik Kanepe ${Date.now()}`, 500_000, 2)
    const customer = await createVerifiedAccount('short-payer')

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

    await request.post(`${API}/api/v1/cart/items`, { headers, data: { sku_id: listing.skuId, quantity: 1 } })
    await request.post(`${API}/api/v1/checkout`, { headers })

    const started = await request.post(`${API}/api/v1/checkout/pay`, {
      headers,
      data: { purpose: 'cart', gateway: 'bank_transfer' },
    })

    expect(started.ok(), await started.text()).toBeTruthy()

    const reference = (await started.json()).data.payment.reference

    const operatorHeaders = { Authorization: `Bearer ${listing.operatorToken}`, Accept: 'application/json' }

    const queue = await request.get(`${API}/api/v1/admin/payments/transfers`, { headers: operatorHeaders })
    const row = ((await queue.json()).data as Array<{ id: string, reference: string }>)
      .find(entry => entry.reference === reference)

    expect(row).toBeTruthy()

    /*
     * A hundred lira short. The tempting mistake is to wave it through as close enough —
     * which is also how a marketplace ships goods for less than they cost.
     */
    const short = await request.post(`${API}/api/v1/admin/payments/transfers/${row!.id}/confirm`, {
      headers: operatorHeaders,
      data: { received_minor: 490_000, value_date: '2026-08-26' },
    })

    expect((await short.json()).data.status).toBe('short_paid')

    await signIn(page, customer.email)
    await gotoInteractive(page, `/checkout/transfer/${reference}`)

    // The figure still owed, not merely "eksik ödeme": a customer told only that
    // something is missing has to work out what, and most will give up and ask.
    await expect(page.getByText('Kalan:')).toBeVisible()
    await expect(page.getByText('₺100,00')).toBeVisible()

    // The basket is still held — nothing was released.
    const cart = await request.get(`${API}/api/v1/cart`, { headers })

    expect((await cart.json()).data.item_count).toBe(1)
  })

  test('finance cannot confirm the same transfer twice', async ({ request }) => {
    const listing = await listProduct(request, `Çift Onay ${Date.now()}`, 300_000, 2)
    const customer = await createVerifiedAccount('double-confirm')

    const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

    await request.post(`${API}/api/v1/addresses`, {
      headers,
      data: { recipient_name: 'Deniz', city: 'İstanbul', address_line1: 'Cadde 1', is_default_shipping: true },
    })

    await request.post(`${API}/api/v1/cart/items`, { headers, data: { sku_id: listing.skuId, quantity: 1 } })
    await request.post(`${API}/api/v1/checkout`, { headers })

    const started = await request.post(`${API}/api/v1/checkout/pay`, {
      headers,
      data: { purpose: 'cart', gateway: 'bank_transfer' },
    })

    const reference = (await started.json()).data.payment.reference
    const operatorHeaders = { Authorization: `Bearer ${listing.operatorToken}`, Accept: 'application/json' }

    const queue = await request.get(`${API}/api/v1/admin/payments/transfers`, { headers: operatorHeaders })
    const row = ((await queue.json()).data as Array<{ id: string, reference: string }>)
      .find(entry => entry.reference === reference)

    const body = { received_minor: 300_000, value_date: '2026-08-26' }

    const first = await request.post(`${API}/api/v1/admin/payments/transfers/${row!.id}/confirm`, {
      headers: operatorHeaders,
      data: body,
    })

    expect(first.ok()).toBeTruthy()

    // Two operators on two stale screens. The second is refused rather than allowed
    // through to release an order twice.
    const second = await request.post(`${API}/api/v1/admin/payments/transfers/${row!.id}/confirm`, {
      headers: operatorHeaders,
      data: body,
    })

    expect(second.status()).toBe(409)

    // And one unit left the shelf, not two.
    const stock = await request.get(`${API}/api/v1/seller/stock`, {
      headers: { Authorization: `Bearer ${listing.sellerToken}`, Accept: 'application/json' },
    })

    const item = ((await stock.json()).data as Array<{ sku: { id: string }, sellable: number }>)
      .find(entry => entry.sku.id === listing.skuId)

    expect(item?.sellable).toBe(1)
  })

  test('a customer cannot open somebody else reference', async ({ page, request }) => {
    const listing = await listProduct(request, `Gizli Referans ${Date.now()}`, 400_000, 2)
    const owner = await createVerifiedAccount('reference-owner')
    const stranger = await createVerifiedAccount('reference-stranger')

    const headers = { Authorization: `Bearer ${owner.token}`, Accept: 'application/json' }

    await request.post(`${API}/api/v1/addresses`, {
      headers,
      data: { recipient_name: 'Deniz', city: 'İstanbul', address_line1: 'Cadde 1', is_default_shipping: true },
    })

    await request.post(`${API}/api/v1/cart/items`, { headers, data: { sku_id: listing.skuId, quantity: 1 } })
    await request.post(`${API}/api/v1/checkout`, { headers })

    const started = await request.post(`${API}/api/v1/checkout/pay`, {
      headers,
      data: { purpose: 'cart', gateway: 'bank_transfer' },
    })

    const reference = (await started.json()).data.payment.reference

    // The reference is short and typable by design, which is exactly what makes it
    // guessable — so somebody else's is a 404 rather than a 403.
    const peek = await request.get(`${API}/api/v1/bank-transfers/${reference}`, {
      headers: { Authorization: `Bearer ${stranger.token}`, Accept: 'application/json' },
    })

    expect(peek.status()).toBe(404)

    await signIn(page, stranger.email)
    await gotoInteractive(page, `/checkout/transfer/${reference}`)

    await expect(page.getByText('Havale kaydı bulunamadı.')).toBeVisible()
  })
})
