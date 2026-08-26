import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { createVerifiedAccount, DEFAULT_PASSWORD } from './support/accounts'
import { listProduct } from './support/catalog'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'

/**
 * The Phase 15 gate, in a browser: E2E-06 from 15_CRITICAL_E2E_SCENARIOS.md.
 *
 * Two sellers, one basket, one payment. The claim being tested is that this is genuinely
 * two things at once — the customer has one order with one number, and each seller has
 * their own with their own goods, their own status and their own money — and that neither
 * of them can see the other's half.
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'
const SELLER_PORTAL = process.env.E2E_SELLER_PORTAL_URL ?? 'http://localhost:3001'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

async function signIn(page: Page, email: string, origin: string): Promise<void> {
  await page.context().clearCookies()
  await page.goto(`${origin}/auth/login`)
  await waitForHydration(page)

  await fillStable(page, '#email', email)
  await fillStable(page, '#password', DEFAULT_PASSWORD)
  await page.getByRole('button', { name: 'Giriş yap' }).click()

  // Waited for rather than assumed: navigating mid-sign-in lands on the login redirect
  // and produces a locator timeout three steps later with no hint of the cause.
  await expect(page).toHaveURL(/\/(account|)$/)
}

test.describe.configure({ timeout: 420_000 })

test.describe('multi-seller order', () => {
  test('one payment becomes one order and two seller orders', async ({ page, request }) => {
    const sofa = await listProduct(request, `Sipariş Kanepe ${Date.now()}`, 1_500_000, 3)
    const lamp = await listProduct(request, `Sipariş Lamba ${Date.now()}`, 250_000, 5)

    const customer = await createVerifiedAccount('multi-buyer')
    const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

    await request.post(`${API}/api/v1/addresses`, {
      headers,
      data: {
        recipient_name: 'Deniz Yılmaz',
        phone: '+905551112233',
        city: 'İstanbul',
        district: 'Kadıköy',
        address_line1: 'Bağdat Caddesi 100',
        is_default_shipping: true,
        is_default_billing: true,
      },
    })

    await request.post(`${API}/api/v1/cart/items`, { headers, data: { sku_id: sofa.skuId, quantity: 1 } })
    await request.post(`${API}/api/v1/cart/items`, { headers, data: { sku_id: lamp.skuId, quantity: 2 } })

    await request.post(`${API}/api/v1/checkout`, { headers })

    const paid = await request.post(`${API}/api/v1/checkout/pay`, {
      headers,
      data: { purpose: 'cart', payment_token: 'tok_success' },
    })

    expect(paid.ok(), await paid.text()).toBeTruthy()

    // --- the customer sees one order -------------------------------------------
    await signIn(page, customer.email, STOREFRONT)
    await gotoInteractive(page, '/account/orders')

    const orderLink = page.locator('a[href^="/account/orders/RC-"]').first()

    await expect(orderLink).toBeVisible()

    const orderNumber = (await orderLink.locator('.font-mono').first().textContent())?.trim() ?? ''

    expect(orderNumber).toMatch(/^RC-/)

    await orderLink.click()
    await waitForHydration(page)

    /*
     * Two blocks, one per seller, because that is how it will actually arrive: two parcels
     * from two shops on two different days. A single status across the whole thing would
     * be right only on the day the last one lands.
     */
    await expect(page.getByText('₺20.000,00')).toBeVisible()
    await expect(page.getByText(`${orderNumber}-1`)).toBeVisible()
    await expect(page.getByText(`${orderNumber}-2`)).toBeVisible()

    // --- each seller sees only their own half ----------------------------------
    const sofaQueue = await request.get(`${API}/api/v1/seller/orders`, {
      headers: { Authorization: `Bearer ${sofa.sellerToken}`, Accept: 'application/json' },
    })

    const sofaRows = (await sofaQueue.json()).data as Array<{ seller_order_number: string, total_minor: number }>

    expect(sofaRows).toHaveLength(1)
    expect(sofaRows[0]!.total_minor).toBe(1_500_000)

    const lampQueue = await request.get(`${API}/api/v1/seller/orders`, {
      headers: { Authorization: `Bearer ${lamp.sellerToken}`, Accept: 'application/json' },
    })

    const lampRows = (await lampQueue.json()).data as Array<{ seller_order_number: string, total_minor: number }>

    expect(lampRows[0]!.total_minor).toBe(500_000)

    // Neither can open the other's.
    const peek = await request.get(
      `${API}/api/v1/seller/orders/${lampRows[0]!.seller_order_number}`,
      { headers: { Authorization: `Bearer ${sofa.sellerToken}`, Accept: 'application/json' } },
    )

    expect(peek.status()).toBe(404)
  })

  test('a seller moves their own parcel and the customer sees only that one move', async ({ page, request }) => {
    const sofa = await listProduct(request, `Kargo Kanepe ${Date.now()}`, 800_000, 3)
    const lamp = await listProduct(request, `Kargo Lamba ${Date.now()}`, 120_000, 5)

    const customer = await createVerifiedAccount('partial-shipper')
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

    await request.post(`${API}/api/v1/cart/items`, { headers, data: { sku_id: sofa.skuId, quantity: 1 } })
    await request.post(`${API}/api/v1/cart/items`, { headers, data: { sku_id: lamp.skuId, quantity: 1 } })
    await request.post(`${API}/api/v1/checkout`, { headers })
    await request.post(`${API}/api/v1/checkout/pay`, {
      headers,
      data: { purpose: 'cart', payment_token: 'tok_success' },
    })

    // --- the sofa seller works their queue in the portal ------------------------
    await signIn(page, sofa.sellerEmail, SELLER_PORTAL)

    /*
     * The absolute URL, because `gotoInteractive` resolves a relative path against
     * Playwright's baseURL — which is the storefront. The seller portal is a separate
     * origin, and a relative path lands on a storefront page that does not exist.
     */
    await gotoInteractive(page, `${SELLER_PORTAL}/orders`)

    await expect(page.getByRole('heading', { name: 'Siparişlerim' })).toBeVisible()

    /*
     * The seller sees what they will actually be paid, next to what the customer paid.
     * Showing only the gross is how a payout becomes a surprise.
     */
    // The column, not the navigation link Phase 16 added beside it.
    await expect(page.getByRole('columnheader', { name: 'Hakediş' })).toBeVisible()

    await page.getByRole('button', { name: 'Aç' }).first().click()

    await expect(page.getByText('Bağdat Caddesi 100')).toBeVisible()

    await page.getByRole('button', { name: 'Onaylandı olarak işaretle' }).click()
    await expect(page.getByText('Sipariş güncellendi.')).toBeVisible()

    await page.getByRole('button', { name: 'Kargoya verildi olarak işaretle' }).click()
    await expect(page.getByText('Sipariş güncellendi.')).toBeVisible()

    // --- the customer's order says partly, not wholly ---------------------------
    const orders = await request.get(`${API}/api/v1/orders`, { headers })
    const orderNumber = ((await orders.json()).data as Array<{ order_number: string }>)[0]!.order_number

    const detail = await request.get(`${API}/api/v1/orders/${orderNumber}`, { headers })
    const body = await detail.json()

    /*
     * "Kısmen kargoda". Telling a customer their order has shipped while one parcel is
     * still on a shelf is a status that is technically true and practically a lie.
     */
    expect(body.data.status).toBe('partially_shipped')

    const shipped = (body.data.sellers as Array<{ status: string }>).filter(s => s.status === 'shipped')

    expect(shipped).toHaveLength(1)
  })

  test('a seller cannot cancel a parcel that has already left', async ({ request }) => {
    const sofa = await listProduct(request, `İptal Kanepe ${Date.now()}`, 400_000, 2)

    const customer = await createVerifiedAccount('late-canceller')
    const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

    await request.post(`${API}/api/v1/addresses`, {
      headers,
      data: { recipient_name: 'Deniz', city: 'İstanbul', address_line1: 'Cadde 1', is_default_shipping: true },
    })

    await request.post(`${API}/api/v1/cart/items`, { headers, data: { sku_id: sofa.skuId, quantity: 1 } })
    await request.post(`${API}/api/v1/checkout`, { headers })
    await request.post(`${API}/api/v1/checkout/pay`, {
      headers,
      data: { purpose: 'cart', payment_token: 'tok_success' },
    })

    const sellerHeaders = { Authorization: `Bearer ${sofa.sellerToken}`, Accept: 'application/json' }

    const queue = await request.get(`${API}/api/v1/seller/orders`, { headers: sellerHeaders })
    const number = ((await queue.json()).data as Array<{ seller_order_number: string }>)[0]!.seller_order_number

    for (const status of ['confirmed', 'shipped']) {
      const moved = await request.post(`${API}/api/v1/seller/orders/${number}/status`, {
        headers: sellerHeaders,
        data: { status },
      })

      expect(moved.ok(), await moved.text()).toBeTruthy()
    }

    /*
     * What happens after a parcel leaves is a return, with a different set of rights.
     * Pressing "cancel" on something already in a van would leave the money and the goods
     * in disagreement.
     */
    const tooLate = await request.post(`${API}/api/v1/seller/orders/${number}/status`, {
      headers: sellerHeaders,
      data: { status: 'cancelled', reason: 'Vazgeçtim' },
    })

    expect(tooLate.status()).toBe(409)
  })

  test('cancelling before shipping puts the stock back', async ({ request }) => {
    const sofa = await listProduct(request, `Stok İade ${Date.now()}`, 300_000, 2)

    const customer = await createVerifiedAccount('stock-returner')
    const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

    await request.post(`${API}/api/v1/addresses`, {
      headers,
      data: { recipient_name: 'Deniz', city: 'İstanbul', address_line1: 'Cadde 1', is_default_shipping: true },
    })

    await request.post(`${API}/api/v1/cart/items`, { headers, data: { sku_id: sofa.skuId, quantity: 2 } })
    await request.post(`${API}/api/v1/checkout`, { headers })
    await request.post(`${API}/api/v1/checkout/pay`, {
      headers,
      data: { purpose: 'cart', payment_token: 'tok_success' },
    })

    const sellerHeaders = { Authorization: `Bearer ${sofa.sellerToken}`, Accept: 'application/json' }

    const sold = await request.get(`${API}/api/v1/seller/stock`, { headers: sellerHeaders })
    const soldRow = ((await sold.json()).data as Array<{ sku: { id: string }, sellable: number }>)
      .find(entry => entry.sku.id === sofa.skuId)

    expect(soldRow?.sellable).toBe(0)

    const queue = await request.get(`${API}/api/v1/seller/orders`, { headers: sellerHeaders })
    const number = ((await queue.json()).data as Array<{ seller_order_number: string }>)[0]!.seller_order_number

    await request.post(`${API}/api/v1/seller/orders/${number}/status`, {
      headers: sellerHeaders,
      data: { status: 'cancelled', reason: 'Depoda hasar bulundu.' },
    })

    /*
     * The stock left when the payment was captured, so cancelling has to put it back —
     * otherwise the warehouse and the ledger disagree, and that only surfaces weeks later
     * as a sale nobody can fulfil.
     */
    const back = await request.get(`${API}/api/v1/seller/stock`, { headers: sellerHeaders })
    const backRow = ((await back.json()).data as Array<{ sku: { id: string }, sellable: number }>)
      .find(entry => entry.sku.id === sofa.skuId)

    expect(backRow?.sellable).toBe(2)
  })
})
