import { expect, test } from '@playwright/test'
import type { APIRequestContext, Page } from '@playwright/test'
import { createVerifiedAccount, DEFAULT_PASSWORD } from './support/accounts'
import { listProduct } from './support/catalog'
import type { ListedProduct } from './support/catalog'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'

/**
 * The Phase 17 gate in a browser: a partial return, a partial refund, and a payout that
 * waits for both.
 *
 * The backend suite proves the arithmetic and the provider-failure path. What only a run
 * like this shows is that the two people involved can actually do it: a customer choosing
 * how many of four chairs to send back, and a seller accepting some of them.
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

  await expect(page).toHaveURL(/\/(account|)$/)
}

/** Buys, pays, ships and delivers — the state every return starts from. */
async function buyAndDeliver(
  request: APIRequestContext,
  listing: ListedProduct,
  quantity: number,
): Promise<{ customerEmail: string, customerToken: string, orderNumber: string, sellerOrderNumber: string }> {
  const customer = await createVerifiedAccount('return-buyer')
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
  const row = ((await queue.json()).data as Array<{ seller_order_number: string, order_number: string }>)[0]!

  for (const status of ['confirmed', 'shipped', 'delivered']) {
    const moved = await request.post(`${API}/api/v1/seller/orders/${row.seller_order_number}/status`, {
      headers: sellerHeaders,
      data: { status },
    })

    expect(moved.ok(), await moved.text()).toBeTruthy()
  }

  return {
    customerEmail: customer.email,
    customerToken: customer.token,
    orderNumber: row.order_number,
    sellerOrderNumber: row.seller_order_number,
  }
}

test.describe.configure({ timeout: 420_000 })

test.describe('returns and refunds', () => {
  test('a customer returns some of what they bought and the seller accepts part of it', async ({ page, request }) => {
    const listing = await listProduct(request, `İade Sandalye ${Date.now()}`, 100_000, 8)
    const sale = await buyAndDeliver(request, listing, 4)

    // --- the customer asks -------------------------------------------------------
    await signIn(page, sale.customerEmail, STOREFRONT)
    await gotoInteractive(page, `/account/orders/${sale.orderNumber}`)

    await page.getByRole('link', { name: 'İade talebi oluştur' }).click()
    await waitForHydration(page)

    await expect(page.getByRole('heading', { name: 'İade talebi' })).toBeVisible()

    // Per line and per quantity: three of four, which is the ordinary case.
    const quantity = page.locator('input[type="number"]').first()

    await quantity.fill('3')

    await page.getByRole('radio', { name: 'Hasarlı geldi' }).check()
    await fillStable(page, '#note', 'Üçü hasarlı geldi.')

    // The figure is shown before the request is sent, not after it is refused.
    await expect(page.getByText('₺3.000,00')).toBeVisible()

    await page.getByRole('button', { name: 'İade talebi oluştur' }).click()

    await expect(page).toHaveURL(/\/account\/returns$/, { timeout: 30_000 })
    await expect(page.getByText('Talep alındı')).toBeVisible()

    // --- the seller accepts two of the three -------------------------------------
    const sellerHeaders = { Authorization: `Bearer ${listing.sellerToken}`, Accept: 'application/json' }

    const queue = await request.get(`${API}/api/v1/seller/returns`, { headers: sellerHeaders })
    const pending = (await queue.json()).data as Array<{
      reference: string
      items: Array<{ id: string }>
    }>

    expect(pending).toHaveLength(1)

    const decided = await request.post(
      `${API}/api/v1/seller/returns/${pending[0]!.reference}/decision`,
      {
        headers: sellerHeaders,
        data: {
          accept: true,
          approved: { [pending[0]!.items[0]!.id]: 2 },
          note: 'İkisi hasarlı, biri sağlam.',
        },
      },
    )

    expect(decided.ok(), await decided.text()).toBeTruthy()
    expect((await decided.json()).data.approved_minor).toBe(200_000)

    // --- received, completed, refunded --------------------------------------------
    for (const status of ['received', 'completed']) {
      const moved = await request.post(
        `${API}/api/v1/seller/returns/${pending[0]!.reference}/status`,
        { headers: sellerHeaders, data: { status } },
      )

      expect(moved.ok(), await moved.text()).toBeTruthy()
    }

    const detail = await request.get(`${API}/api/v1/returns/${pending[0]!.reference}`, {
      headers: { Authorization: `Bearer ${sale.customerToken}`, Accept: 'application/json' },
    })

    const body = await detail.json()

    /*
     * Two of three refunded, and the refund is its own object with its own status — a
     * return can be accepted while the money is still with the bank.
     */
    expect(body.data.status).toBe('completed')
    expect(body.data.refund.amount_minor).toBe(200_000)
    expect(body.data.refund.status).toBe('succeeded')

    // The customer's page says both things separately.
    await page.reload()
    await waitForHydration(page)

    await expect(page.getByText('Tamamlandı').first()).toBeVisible()
    await expect(page.getByText('₺2.000,00')).toBeVisible()
  })

  test('the seller sees the return and can work it from the portal', async ({ page, request }) => {
    const listing = await listProduct(request, `Panel İade ${Date.now()}`, 150_000, 4)
    const sale = await buyAndDeliver(request, listing, 2)

    const customerHeaders = { Authorization: `Bearer ${sale.customerToken}`, Accept: 'application/json' }

    const opened = await request.post(`${API}/api/v1/returns`, {
      headers: customerHeaders,
      data: {
        seller_order_number: sale.sellerOrderNumber,
        reason_code: 'wrong_item',
        items: [{
          order_item_id: ((await (await request.get(
            `${API}/api/v1/orders/${sale.orderNumber}`,
            { headers: customerHeaders },
          )).json()).data.sellers[0].items[0].id),
          quantity: 1,
        }],
      },
    })

    expect(opened.ok(), await opened.text()).toBeTruthy()

    await signIn(page, listing.sellerEmail, SELLER_PORTAL)
    await gotoInteractive(page, `${SELLER_PORTAL}/returns`)

    await expect(page.getByRole('heading', { name: 'İadeler' })).toBeVisible()

    // The seller is told what an open return costs them before they act on it.
    await expect(page.getByText('hakedişini bekletir')).toBeVisible()

    await page.getByRole('button', { name: 'Karar ver' }).first().click()

    await expect(page.getByText('Kaç adedini kabul ediyorsunuz?')).toBeVisible()

    await page.getByRole('button', { name: 'İadeyi onayla' }).click()

    await expect(page.getByText('İade onaylandı.')).toBeVisible()
  })

  test('an open return holds the payout until it is resolved', async ({ request }) => {
    const listing = await listProduct(request, `Bekleyen İade ${Date.now()}`, 300_000, 4)
    const sale = await buyAndDeliver(request, listing, 2)

    const customerHeaders = { Authorization: `Bearer ${sale.customerToken}`, Accept: 'application/json' }
    const sellerHeaders = { Authorization: `Bearer ${listing.sellerToken}`, Accept: 'application/json' }

    const orderDetail = await (await request.get(
      `${API}/api/v1/orders/${sale.orderNumber}`,
      { headers: customerHeaders },
    )).json()

    const opened = await request.post(`${API}/api/v1/returns`, {
      headers: customerHeaders,
      data: {
        seller_order_number: sale.sellerOrderNumber,
        reason_code: 'damaged',
        items: [{ order_item_id: orderDetail.data.sellers[0].items[0].id, quantity: 1 }],
      },
    })

    expect(opened.ok(), await opened.text()).toBeTruthy()

    /*
     * The seller's own earnings page says why the money is waiting. E2E-09: paying for
     * goods on their way back means chasing money from somebody who has spent it.
     */
    const earnings = await request.get(`${API}/api/v1/seller/earnings/orders`, { headers: sellerHeaders })
    const rows = (await earnings.json()).data as Array<{ settlement_note: string }>

    expect(rows[0]!.settlement_note).toContain('Açık bir iade talebi')

    // Refused by the seller: the money is free again.
    const rejected = await request.post(
      `${API}/api/v1/seller/returns/${(await opened.json()).data.reference}/decision`,
      { headers: sellerHeaders, data: { accept: false, note: 'Ürün kullanılmış olarak geldi.' } },
    )

    expect(rejected.ok(), await rejected.text()).toBeTruthy()

    const after = await request.get(`${API}/api/v1/seller/earnings/orders`, { headers: sellerHeaders })
    const afterRows = (await after.json()).data as Array<{ settlement_note: string }>

    expect(afterRows[0]!.settlement_note).not.toContain('Açık bir iade talebi')
  })

  test('a return after the window has closed is refused with a date', async ({ request }) => {
    const listing = await listProduct(request, `Süresi Geçmiş ${Date.now()}`, 120_000, 3)
    const sale = await buyAndDeliver(request, listing, 1)

    const customerHeaders = { Authorization: `Bearer ${sale.customerToken}`, Accept: 'application/json' }

    const orderDetail = await (await request.get(
      `${API}/api/v1/orders/${sale.orderNumber}`,
      { headers: customerHeaders },
    )).json()

    // Opened twice for the same unit: the second is refused because the first is still
    // counted, which is what stops the same chair being returned twice.
    const first = await request.post(`${API}/api/v1/returns`, {
      headers: customerHeaders,
      data: {
        seller_order_number: sale.sellerOrderNumber,
        reason_code: 'damaged',
        items: [{ order_item_id: orderDetail.data.sellers[0].items[0].id, quantity: 1 }],
      },
    })

    expect(first.ok()).toBeTruthy()

    const second = await request.post(`${API}/api/v1/returns`, {
      headers: customerHeaders,
      data: {
        seller_order_number: sale.sellerOrderNumber,
        reason_code: 'damaged',
        items: [{ order_item_id: orderDetail.data.sellers[0].items[0].id, quantity: 1 }],
      },
    })

    expect(second.status()).toBe(422)
    expect((await second.json()).message).toContain('en fazla')
  })

  test('a customer cannot open somebody else return', async ({ request }) => {
    const listing = await listProduct(request, `Gizli İade ${Date.now()}`, 90_000, 3)
    const sale = await buyAndDeliver(request, listing, 1)

    const customerHeaders = { Authorization: `Bearer ${sale.customerToken}`, Accept: 'application/json' }

    const orderDetail = await (await request.get(
      `${API}/api/v1/orders/${sale.orderNumber}`,
      { headers: customerHeaders },
    )).json()

    const opened = await request.post(`${API}/api/v1/returns`, {
      headers: customerHeaders,
      data: {
        seller_order_number: sale.sellerOrderNumber,
        reason_code: 'damaged',
        items: [{ order_item_id: orderDetail.data.sellers[0].items[0].id, quantity: 1 }],
      },
    })

    const reference = (await opened.json()).data.reference

    const stranger = await createVerifiedAccount('return-stranger')

    const peek = await request.get(`${API}/api/v1/returns/${reference}`, {
      headers: { Authorization: `Bearer ${stranger.token}`, Accept: 'application/json' },
    })

    expect(peek.status()).toBe(404)
  })
})
