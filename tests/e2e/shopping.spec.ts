import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { createVerifiedAccount, DEFAULT_PASSWORD } from './support/accounts'
import { listProduct } from './support/catalog'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'

/**
 * The Phase 10 gate, in a browser: search, favourites, and a basket that tells the truth.
 *
 * The unit suite proves the arithmetic — that a price rise is reported and not applied,
 * that two baskets cannot hold more stock than exists. What only a run like this proves is
 * that the pages a customer actually uses are wired to those rules rather than to their own
 * idea of them, and that a price changed by a seller in one application shows up as a
 * warning in another.
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

async function signIn(page: Page, email: string): Promise<void> {
  await page.context().clearCookies()
  await page.goto(`${STOREFRONT}/auth/login`)
  await waitForHydration(page)

  await fillStable(page, '#email', email)
  await fillStable(page, '#password', DEFAULT_PASSWORD)
  await page.getByRole('button', { name: 'Giriş yap' }).click()

  await expect(page).toHaveURL(/\/account$/)
}

test.describe.configure({ timeout: 300_000 })

test.describe('shopping', () => {
  test('a customer adds to the basket and is told when the price moves', async ({ page, request }) => {
    const listing = await listProduct(request, `Sepet Kanepe ${Date.now()}`, 2_000_000, 5)
    const customer = await createVerifiedAccount('shopper')

    await signIn(page, customer.email)
    await gotoInteractive(page, `${STOREFRONT}/catalog/${listing.slug}`)

    await page.getByRole('button', { name: 'Sepete ekle' }).click()
    await expect(page.getByText('Ürün sepetinize eklendi.')).toBeVisible()

    // The seller raises the price while the basket sits there — a Tuesday on a
    // marketplace, and the case the whole snapshot mechanism exists for.
    /*
     * Through the pricing endpoint rather than by editing the SKU: that is the path a
     * seller actually takes, and it is the one that writes to price_history — so the rise
     * this test depends on is a real repricing rather than a poke at a column.
     */
    const repriced = await request.post(`${API}/api/v1/seller/prices/bulk`, {
      headers: { Authorization: `Bearer ${listing.sellerToken}`, Accept: 'application/json' },
      data: { prices: [{ sku_id: listing.skuId, list_price_minor: 2_600_000 }] },
    })

    expect(repriced.ok()).toBeTruthy()

    await gotoInteractive(page, `${STOREFRONT}/cart`)

    await expect(page.getByRole('heading', { name: 'Sepetim' })).toBeVisible()

    /*
     * Both figures, and the old one still in force. Quietly charging the new price would
     * be the failure this phase is built to prevent.
     */
    await expect(page.getByText('Fiyat arttı')).toBeVisible()
    await expect(page.getByText('₺20.000,00', { exact: false }).first()).toBeVisible()

    const wallet = await request.get(`${API}/api/v1/cart`, {
      headers: { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' },
    })

    const body = await wallet.json()

    expect(body.data.subtotal_minor).toBe(2_000_000)
    expect(body.issues[0].issue).toBe('price_increased')
    expect(body.issues[0].blocks_checkout).toBe(true)

    // Accepting is an explicit act, so the higher figure is agreed to rather than applied.
    await page.getByRole('button', { name: 'Güncel fiyatları kabul et' }).click()
    await expect(page.getByText('Güncel fiyatlar kabul edildi.')).toBeVisible()

    const after = await request.get(`${API}/api/v1/cart`, {
      headers: { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' },
    })

    expect((await after.json()).data.subtotal_minor).toBe(2_600_000)
  })

  test('checkout holds the stock, and backing out gives it back', async ({ request }) => {
    const listing = await listProduct(request, `Stok Kanepe ${Date.now()}`, 1_500_000, 3)

    const first = await createVerifiedAccount('holder')
    const second = await createVerifiedAccount('waiter')

    const firstHeaders = { Authorization: `Bearer ${first.token}`, Accept: 'application/json' }
    const secondHeaders = { Authorization: `Bearer ${second.token}`, Accept: 'application/json' }

    await request.post(`${API}/api/v1/cart/items`, {
      headers: firstHeaders,
      data: { sku_id: listing.skuId, quantity: 3 },
    })

    // Nothing is held while a basket merely sits there: a tab left open for a week must
    // not keep a sofa off the market.
    const second_add = await request.post(`${API}/api/v1/cart/items`, {
      headers: secondHeaders,
      data: { sku_id: listing.skuId, quantity: 2 },
    })

    expect(second_add.ok()).toBeTruthy()

    const checkout = await request.post(`${API}/api/v1/cart/checkout`, { headers: firstHeaders })

    expect(checkout.ok()).toBeTruthy()
    expect((await checkout.json()).data.status).toBe('checking_out')

    /*
     * All three are now held, so there is nothing left for the second basket. Its line is
     * removed and it is told why — rather than being allowed through to fail at payment,
     * which is the worst possible moment to learn something sold out.
     */
    const blocked = await request.post(`${API}/api/v1/cart/checkout`, { headers: secondHeaders })
    const blockedBody = await blocked.json()

    // Answered with the basket and an explanation rather than a bare refusal: "bu ürün
    // tükendi" is something a customer can act on, and "sepetiniz boş" alone is not.
    expect(blocked.ok()).toBeTruthy()
    expect(blockedBody.issues.some((issue: { issue: string }) => issue.issue === 'out_of_stock')).toBe(true)
    expect(blockedBody.data.item_count).toBe(0)

    const emptied = await request.get(`${API}/api/v1/cart`, { headers: secondHeaders })
    const emptiedBody = await emptied.json()

    expect(emptiedBody.data.item_count).toBe(0)

    // The first customer changes their mind; the stock returns immediately rather than
    // waiting out the fifteen-minute hold, because fifteen minutes of a sofa being
    // unbuyable for no reason is fifteen minutes of somebody else being told "sold out".
    await request.delete(`${API}/api/v1/cart/checkout`, { headers: firstHeaders })

    const readded = await request.post(`${API}/api/v1/cart/items`, {
      headers: secondHeaders,
      data: { sku_id: listing.skuId, quantity: 2 },
    })

    expect(readded.ok()).toBeTruthy()

    const nowAvailable = await request.post(`${API}/api/v1/cart/checkout`, { headers: secondHeaders })

    expect((await nowAvailable.json()).data.status).toBe('checking_out')
  })

  test('a basket cannot be edited while it is being paid for', async ({ request }) => {
    const listing = await listProduct(request, `Kilitli Kanepe ${Date.now()}`, 900_000, 4)
    const customer = await createVerifiedAccount('locked-cart')

    const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

    await request.post(`${API}/api/v1/cart/items`, {
      headers,
      data: { sku_id: listing.skuId, quantity: 1 },
    })

    await request.post(`${API}/api/v1/cart/checkout`, { headers })

    // Otherwise the held quantity and the basket would disagree, and the order would be
    // built from one of them.
    const rejected = await request.post(`${API}/api/v1/cart/items`, {
      headers,
      data: { sku_id: listing.skuId, quantity: 1 },
    })

    expect(rejected.status()).toBe(409)
  })

  test('a customer keeps a favourite and finds it again', async ({ page, request }) => {
    const listing = await listProduct(request, `Favori Kanepe ${Date.now()}`, 1_100_000, 2)
    const customer = await createVerifiedAccount('favouriter')

    await signIn(page, customer.email)
    await gotoInteractive(page, `${STOREFRONT}/catalog/${listing.slug}`)

    await page.getByRole('button', { name: 'Favorilere ekle' }).click()
    await expect(page.getByRole('button', { name: 'Favorilerimde' })).toBeVisible()

    await gotoInteractive(page, `${STOREFRONT}/favorites`)

    await expect(page.getByRole('heading', { name: 'Favorilerim' })).toBeVisible()
    await expect(page.getByRole('link', { name: new RegExp('Favori Kanepe') }).first()).toBeVisible()

    // Favouriting twice is favouriting once, so the API count is the honest check.
    const list = await request.get(`${API}/api/v1/favorites`, {
      headers: { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' },
    })

    expect((await list.json()).data).toHaveLength(1)
  })

  test('search finds a listing and offers facets to narrow it', async ({ request }) => {
    const name = `Bergama Halisi ${Date.now()}`
    await listProduct(request, name, 700_000, 2)

    /*
     * The catalogue is embedded so the semantic half of the search has something to work
     * with. Cheap to repeat — a product whose text has not changed is skipped.
     */
    const { execFile } = await import('node:child_process')
    const { promisify } = await import('node:util')
    const run = promisify(execFile)

    await run('docker', [
      'compose', 'exec', '-T', 'api',
      'php', 'artisan', 'refconcept:embed-catalogue',
    ])

    const response = await request.get(
      `${API}/api/v1/catalog/products?search=${encodeURIComponent('Bergama')}`,
      { headers: { Accept: 'application/json' } },
    )

    expect(response.ok()).toBeTruthy()

    const body = await response.json()

    expect(body.data.length).toBeGreaterThan(0)
    expect(body.data.some((product: { name: string }) => product.name.includes('Bergama'))).toBe(true)

    // Facets describe the whole result rather than the page, which is the only way a count
    // behind an unclicked filter means anything.
    expect(body.facets.categories.length).toBeGreaterThan(0)
    expect(body.facets.price_bands.every((band: { count: number }) => band.count > 0)).toBe(true)

    // And a search that matches nothing returns nothing rather than the whole catalogue.
    const empty = await request.get(`${API}/api/v1/catalog/products?search=zzzzqqqqxyz`, {
      headers: { Accept: 'application/json' },
    })

    expect((await empty.json()).data).toHaveLength(0)
  })
})
