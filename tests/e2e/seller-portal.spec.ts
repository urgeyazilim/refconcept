import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { createVerifiedAccount, DEFAULT_PASSWORD } from './support/accounts'
import { listProduct } from './support/catalog'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'
import { signInThrough } from './support/signin'

/**
 * The Phase 19 gate in a browser: a seller's whole working day, and the wall around it.
 *
 * The unit suite proves the rules — the last owner cannot demote themselves, one seller
 * cannot address another's team, a parcel can carry part of an order. What only a run like
 * this shows is that the portal is a place somebody can actually work: the front page says
 * what is waiting, the parcel screen knows what is left on the shelf without the seller
 * doing arithmetic, and a colleague added on Monday can do the job on Tuesday.
 *
 * Isolation is checked from the browser as well as from the API, because the interesting
 * failure is a page that fetches somebody else's data and renders it before anybody notices.
 */

const SELLER_PORTAL = process.env.E2E_SELLER_PORTAL_URL ?? 'http://localhost:3001'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

async function signIn(page: Page, email: string): Promise<void> {
  // Retries once through a rate-limit refusal. See tests/e2e/support/signin.ts — the
  // limiter is production-strength on purpose, and a suite this long trips it.
  await signInThrough(page, SELLER_PORTAL, email)
}

/** Buys from the listing and pays, leaving one seller order awaiting confirmation. */
async function buy(
  request: Parameters<typeof listProduct>[0],
  listing: Awaited<ReturnType<typeof listProduct>>,
  quantity = 1,
): Promise<void> {
  const customer = await createVerifiedAccount('portal-buyer')
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
}

test.describe.configure({ timeout: 420_000 })

test.describe('seller portal', () => {
  test('the front page leads with the work still waiting', async ({ page, request }) => {
    const listing = await listProduct(request, `Portal Koltuk ${Date.now()}`, 400_000, 5)

    await buy(request, listing)

    await signIn(page, listing.sellerEmail)
    await gotoInteractive(page, SELLER_PORTAL)

    /*
     * The queue above the money. A seller already knows roughly what they sold; what they
     * do not know is that an order has been sitting unconfirmed since Friday.
     */
    await expect(page.getByRole('heading', { name: 'Sizi bekleyenler' })).toBeVisible()
    await expect(page.getByTestId('seller-queue-unconfirmed')).toContainText('1')

    // And it is a way in, not a number to write down.
    await page.getByTestId('seller-queue-unconfirmed').click()
    await expect(page).toHaveURL(/\/orders$/)
  })

  test('a parcel carries part of an order and the screen knows what is left', async ({ page, request }) => {
    const listing = await listProduct(request, `Portal Sandalye ${Date.now()}`, 150_000, 8)

    await buy(request, listing, 3)

    const sellerHeaders = { Authorization: `Bearer ${listing.sellerToken}`, Accept: 'application/json' }

    const queue = await request.get(`${API}/api/v1/seller/orders`, { headers: sellerHeaders })
    const number = ((await queue.json()).data as Array<{ seller_order_number: string }>)[0]!.seller_order_number

    await request.post(`${API}/api/v1/seller/orders/${number}/status`, {
      headers: sellerHeaders,
      data: { status: 'confirmed' },
    })

    await signIn(page, listing.sellerEmail)
    await gotoInteractive(page, `${SELLER_PORTAL}/shipping`)

    await expect(page.getByRole('heading', { name: 'Kargo' })).toBeVisible()

    await page.getByTestId('shipping-order').filter({ hasText: number }).click()

    // Three ordered, and the page says so rather than making the seller work it out.
    await expect(page.getByTestId('pending-line')).toContainText('3 sipariş edildi, 3 kaldı')

    await page.getByTestId('carrier').fill('Test Kargo')
    await page.getByTestId('tracking').fill('TK-PORTAL-1')
    await page.getByRole('spinbutton').first().fill('1')
    await page.getByTestId('ship').click()

    await expect(page.getByText('Kargo kaydedildi.')).toBeVisible()

    // One gone, two left — and the order has not become "kargoya verildi", because a
    // customer told that while two chairs are still in the warehouse waits on a lie.
    await expect(page.getByTestId('pending-line')).toContainText('3 sipariş edildi, 2 kaldı')
    await expect(page.getByTestId('shipment-row')).toContainText('TK-PORTAL-1')
  })

  test('an owner adds a colleague who can then do the work', async ({ page, request }) => {
    const listing = await listProduct(request, `Portal Masa ${Date.now()}`, 220_000, 4)
    const colleague = await createVerifiedAccount('portal-staff')

    await buy(request, listing)

    await signIn(page, listing.sellerEmail)
    await gotoInteractive(page, `${SELLER_PORTAL}/team`)

    await expect(page.getByRole('heading', { name: 'Ekibim' })).toBeVisible()

    await page.getByTestId('team-email').fill(colleague.email)
    await page.getByTestId('team-role').selectOption('seller-staff')
    await page.getByRole('button', { name: 'Ekle' }).click()

    await expect(page.getByText('ekibinize eklendi.')).toBeVisible()
    await expect(page.getByTestId('team-row')).toHaveCount(2)

    /*
     * The point of the whole feature: the colleague signs in as themselves and finds the
     * company's work rather than an invitation to start applying. A shared login is how
     * this gets solved when the platform does not solve it — and then every audit entry
     * says "the seller" and means nobody.
     */
    await signIn(page, colleague.email)
    await gotoInteractive(page, SELLER_PORTAL)

    await expect(page.getByTestId('seller-queue-unconfirmed')).toContainText('1')
  })

  test('staff see the team and cannot change it', async ({ page, request }) => {
    const listing = await listProduct(request, `Portal Dolap ${Date.now()}`, 310_000, 2)
    const colleague = await createVerifiedAccount('portal-staff-readonly')

    const ownerHeaders = { Authorization: `Bearer ${listing.sellerToken}`, Accept: 'application/json' }

    const added = await request.post(`${API}/api/v1/seller/team`, {
      headers: ownerHeaders,
      data: { email: colleague.email, role: 'seller-staff' },
    })

    expect(added.ok(), await added.text()).toBeTruthy()

    await signIn(page, colleague.email)
    await gotoInteractive(page, `${SELLER_PORTAL}/team`)

    // Absent rather than disabled, with the reason said out loud.
    await expect(page.getByText('Ekibi yalnızca yetkili hesaplar değiştirebilir.')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Ekle' })).toHaveCount(0)

    const refused = await request.post(`${API}/api/v1/seller/team`, {
      headers: { Authorization: `Bearer ${colleague.token}`, Accept: 'application/json' },
      data: { email: 'kimse@yok.test', role: 'seller-staff' },
    })

    expect(refused.status()).toBe(403)
  })

  test('the last owner cannot lock themselves out', async ({ page, request }) => {
    const listing = await listProduct(request, `Portal Kitaplık ${Date.now()}`, 180_000, 3)

    await signIn(page, listing.sellerEmail)
    await gotoInteractive(page, `${SELLER_PORTAL}/team`)

    /*
     * No button at all. A company with no owner is a company where nobody can add one
     * back, and the only way out is a support ticket and a console command.
     */
    await expect(page.getByTestId('team-row')).toContainText('Tek yetkili')
    await expect(page.getByRole('button', { name: 'Çıkar' })).toHaveCount(0)
  })

  test('one seller never sees another seller work', async ({ page, request }) => {
    const mine = await listProduct(request, `Portal Benim ${Date.now()}`, 260_000, 3)
    const theirs = await listProduct(request, `Portal Onun ${Date.now()}`, 260_000, 3)

    await buy(request, theirs, 2)

    await signIn(page, mine.sellerEmail)
    await gotoInteractive(page, SELLER_PORTAL)

    // Their order is not on my queue, and my dashboard has no id to ask with.
    await expect(page.getByTestId('seller-queue-unconfirmed')).toContainText('0')

    await gotoInteractive(page, `${SELLER_PORTAL}/shipping`)
    await expect(page.getByTestId('shipping-empty')).toBeVisible()

    const theirTeam = await request.get(`${API}/api/v1/seller/team`, {
      headers: { Authorization: `Bearer ${mine.sellerToken}`, Accept: 'application/json' },
    })

    const emails = ((await theirTeam.json()).data as Array<{ email: string }>).map(row => row.email)

    expect(emails).not.toContain(theirs.sellerEmail)
  })
})
