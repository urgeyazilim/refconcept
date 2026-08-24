import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { DEFAULT_PASSWORD } from './support/accounts'
import { createApprovedSeller, pngBuffer, TEST_CATEGORY_SLUG } from './support/sellers'
import { fillStable } from './support/forms'
import { gotoHydrated, gotoInteractive, waitForHydration } from './support/hydration'

/**
 * The Phase 3 gate: a seller lists a product, a reviewer approves it, and only then
 * does a customer see it.
 *
 * This is the scenario the whole phase exists to make true, and it spans three
 * separate applications on three ports plus the API. Unit tests already prove each
 * link — that `publiclyVisible()` excludes an unapproved listing, that the policy
 * refuses a rival seller — but only a run like this proves the three front ends are
 * actually wired to those rules rather than to their own idea of them.
 *
 * The negative assertion is the important one: the catalogue is checked *before*
 * approval as well as after. A test that only looks after approval would pass just as
 * happily if nothing were gated at all.
 */

const PORTAL = process.env.E2E_SELLER_PORTAL_URL ?? 'http://localhost:3001'
const ADMIN = process.env.E2E_ADMIN_PANEL_URL ?? 'http://localhost:3002'
const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

/**
 * Signs in to one of the three apps.
 *
 * Cookies are cleared first because a browser scopes them by host and ignores the
 * port: the seller portal on :3001 and the admin panel on :3002 are one cookie jar
 * in development, so signing into the second silently signs you into the first. The
 * `guest` middleware then bounces the next /auth/login away and the form is never
 * there to fill. In production the three apps sit on separate domains and the problem
 * does not arise, so this belongs in the test rather than in the apps.
 */
async function signIn(page: Page, base: string, email: string): Promise<void> {
  await page.context().clearCookies()
  await page.goto(`${base}/auth/login`)
  await waitForHydration(page)

  await fillStable(page, '#email', email)
  await fillStable(page, '#password', DEFAULT_PASSWORD)
  await page.getByRole('button', { name: 'Giriş yap' }).click()

  await expect(page).toHaveURL(new RegExp(`${base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/?$`))
}

/*
 * Each test builds an approved seller from scratch — registration, a verification
 * mail read out of Mailpit, the full onboarding file and an operator's approval —
 * before it can even begin. That is minutes of genuine work across three apps, so
 * the default 90s budget is raised rather than the setup being faked.
 */
test.describe.configure({ timeout: 300_000 })

test.describe('product lifecycle', () => {
  test('a listing reaches the catalogue only after a reviewer approves it', async ({ page, request }) => {
    const productName = `E2E Bouclé Kanepe ${Date.now()}`

    const seller = await createApprovedSeller(request, {
      companyName: 'Atlas Mobilya Anonim Şirketi',
      displayName: 'Atlas Mobilya',
    })

    // --- the seller creates a draft ------------------------------------------
    await signIn(page, PORTAL, seller.account.email)

    await page.getByRole('link', { name: 'Ürünlerim' }).first().click()
    await waitForHydration(page)

    await expect(page.getByRole('heading', { name: 'Henüz ürün eklemediniz' })).toBeVisible()

    await page.getByRole('link', { name: 'İlk ürünü ekle' }).click()
    await waitForHydration(page)

    await fillStable(page, '#name', productName)
    await page.locator('#primary_category_id').selectOption({ label: 'Kanepe' })
    await page.getByRole('button', { name: 'Ürünü oluştur' }).click()

    // The editor is where completeness is worked through, so creation lands there.
    await expect(page.getByRole('heading', { name: productName })).toBeVisible()
    await expect(page.getByText('En az bir ürün görseli')).toBeVisible()

    const productId = new URL(page.url()).pathname.split('/').pop()!

    // --- the gate holds while the listing is a draft ---------------------------
    await expect(page.getByRole('button', { name: 'İncelemeye gönder' })).toBeDisabled()

    // --- description and category attributes -----------------------------------
    await page.locator('#description').fill(
      'Bouclé kumaş kaplı, modüler üç kişilik oturma grubu. Masif kayın iskelet.',
    )

    // The attribute fields are driven by the category, not hard-coded in the form.
    const colour = page.locator('#attr-color')
    await expect(colour).toBeVisible()
    await colour.selectOption({ index: 1 })
    await page.locator('#attr-material').selectOption({ index: 1 })

    await page.getByRole('button', { name: 'Değişiklikleri kaydet' }).click()

    // The checklist is server-derived, so the description requirement disappearing is
    // proof the save landed rather than proof the button was clicked.
    await expect(page.getByText('Ürün açıklaması')).toHaveCount(0)

    // --- imagery ----------------------------------------------------------------
    await page.locator('input[type="file"]').setInputFiles({
      name: 'kanepe.png',
      mimeType: 'image/png',
      buffer: pngBuffer(),
    })

    await expect(page.getByText('Kapak')).toBeVisible()

    // --- a sales option with a price and dimensions ------------------------------
    await page.getByRole('button', { name: 'Seçenek ekle' }).click()

    await fillStable(page, '#sku', 'ATL-KNP-001')
    await fillStable(page, '#list_price', '48.900,00')
    await fillStable(page, '#stock_quantity', '5')
    await fillStable(page, '#width_mm', '2200')
    await fillStable(page, '#depth_mm', '950')

    await page.getByRole('button', { name: 'Seçeneği ekle' }).click()

    // Prices are integer minor units on the wire; the formatted figure comes back
    // from the server, so seeing it here proves the round trip kept the exact value.
    await expect(page.getByText('48.900,00').first()).toBeVisible()

    // --- the checklist clears and submission unlocks ------------------------------
    await expect(page.getByText('Bu ürün incelemeye gönderilmeye hazır.')).toBeVisible()

    const submit = page.getByRole('button', { name: 'İncelemeye gönder' })
    await expect(submit).toBeEnabled()
    await submit.click()

    await expect(page.getByText(/incelemeye gönderildi/)).toBeVisible()
    await expect(page.getByText(/incelemede olduğu için düzenlenemez/)).toBeVisible()

    // --- the customer cannot see it yet -------------------------------------------
    const beforeApproval = await request.get(
      `${API}/api/v1/catalog/products?search=${encodeURIComponent(productName)}`,
    )

    expect(beforeApproval.ok()).toBeTruthy()
    expect((await beforeApproval.json()).data).toHaveLength(0)

    // --- the reviewer decides ------------------------------------------------------
    await signIn(page, ADMIN, seller.operator.email)

    await page.getByRole('link', { name: 'Ürünler' }).first().click()
    await waitForHydration(page)

    await page.getByRole('row', { name: new RegExp(productName) })
      .getByRole('link', { name: 'İncele' })
      .click()

    await waitForHydration(page)

    await expect(page.getByRole('heading', { name: productName })).toBeVisible()
    await expect(page.getByText('Zorunlu alanların tamamı dolu.')).toBeVisible()

    await page.getByRole('button', { name: 'İncelemeye al' }).click()
    await expect(page.getByText('Ürün incelemeye alındı.')).toBeVisible()

    // A decision without a reason is refused server-side; the button mirrors that.
    await expect(page.getByRole('button', { name: 'Onayla ve yayınla' })).toBeDisabled()

    await page.locator('#reason').fill('Görseller, ölçüler ve açıklama eksiksiz.')
    await page.getByRole('button', { name: 'Onayla ve yayınla' }).click()

    await expect(page.getByText(/onaylandı/)).toBeVisible()

    // --- and now the customer sees it ------------------------------------------------
    await gotoHydrated(page, `${STOREFRONT}/catalog?search=${encodeURIComponent(productName)}`)

    const card = page.getByRole('link', { name: new RegExp(productName) })
    await expect(card).toBeVisible()

    await card.click()
    await waitForHydration(page)

    await expect(page.getByRole('heading', { level: 1, name: productName })).toBeVisible()
    await expect(page.getByText('48.900,00 ₺').first()).toBeVisible()
    await expect(page.getByText('Atlas Mobilya')).toBeVisible()

    // Dimensions are shown in centimetres because nobody shops for a 2200 mm sofa.
    await expect(page.getByText('220 cm')).toBeVisible()

    // --- pausing takes it off sale without another review -----------------------------
    await signIn(page, PORTAL, seller.account.email)
    await gotoInteractive(page, `${PORTAL}/products/${productId}`)

    await page.getByRole('button', { name: 'Satıştan kaldır' }).click()
    await expect(page.getByText('Ürün satıştan kaldırıldı.')).toBeVisible()

    const afterPause = await request.get(
      `${API}/api/v1/catalog/products?search=${encodeURIComponent(productName)}`,
    )

    expect((await afterPause.json()).data).toHaveLength(0)
  })

  test('a rejection names the fields at fault and reopens the listing for editing', async ({ page, request }) => {
    const productName = `E2E Eksik Ürün ${Date.now()}`

    const seller = await createApprovedSeller(request, {
      companyName: 'Nova Yaşam Anonim Şirketi',
      displayName: 'Nova Yaşam',
    })

    const headers = { Authorization: `Bearer ${seller.account.token}`, Accept: 'application/json' }

    // Built through the API: this test is about the rejection, not about the form.
    const categories = await (await request.get(`${API}/api/v1/catalog/categories`)).json()
    const category = categories.data.find(
      (item: { slug: string }) => item.slug === TEST_CATEGORY_SLUG,
    )

    const attributes = await (await request.get(
      `${API}/api/v1/catalog/categories/${TEST_CATEGORY_SLUG}/attributes`,
    )).json()

    const required = attributes.data
      .filter((attribute: { is_required: boolean }) => attribute.is_required)
      .map((attribute: { code: string, values: Array<{ value: string }> }) => ({
        code: attribute.code,
        value: attribute.values[0]!.value,
      }))

    const created = await (await request.post(`${API}/api/v1/seller/products`, {
      headers,
      data: {
        name: productName,
        description: 'Deneme amaçlı ürün açıklaması.',
        primary_category_id: category.id,
        attributes: required,
      },
    })).json()

    const productId = created.data.id

    await request.post(`${API}/api/v1/seller/products/${productId}/media`, {
      headers: { Authorization: `Bearer ${seller.account.token}` },
      multipart: {
        file: { name: 'urun.png', mimeType: 'image/png', buffer: pngBuffer() },
      },
    })

    await request.post(`${API}/api/v1/seller/products/${productId}/skus`, {
      headers,
      data: {
        sku: 'NOVA-001',
        list_price_minor: 1_299_00,
        stock_quantity: 3,
        dimensions: { width_mm: 800, depth_mm: 800 },
      },
    })

    const submitted = await request.post(`${API}/api/v1/seller/products/${productId}/submit`, { headers })
    expect(submitted.ok()).toBeTruthy()

    // --- the reviewer rejects with named fields --------------------------------
    await signIn(page, ADMIN, seller.operator.email)
    await gotoInteractive(page, `${ADMIN}/products/${productId}`)

    await page.getByRole('button', { name: 'İncelemeye al' }).click()
    await expect(page.getByText('Ürün incelemeye alındı.')).toBeVisible()

    await page.locator('#reason').fill('Görsel çözünürlüğü yetersiz, açıklama çok kısa.')
    await page.getByRole('button', { name: 'Görseller' }).click()
    await page.getByRole('button', { name: 'Açıklama' }).click()
    await page.getByRole('button', { name: 'Reddet' }).click()

    await expect(page.getByText(/reddedildi/)).toBeVisible()

    // The decision, its reason and the flagged fields are all on the record.
    await expect(page.getByText('Görsel çözünürlüğü yetersiz, açıklama çok kısa.')).toBeVisible()
    await expect(page.getByText(/İşaretlenen alanlar:.*media/)).toBeVisible()

    // --- the listing stays out of the catalogue and reopens for the seller ------
    const catalogue = await request.get(
      `${API}/api/v1/catalog/products?search=${encodeURIComponent(productName)}`,
    )

    expect((await catalogue.json()).data).toHaveLength(0)

    await signIn(page, PORTAL, seller.account.email)
    await gotoInteractive(page, `${PORTAL}/products/${productId}`)

    // Rejected means editable again: a seller who cannot fix the problem can only
    // create a duplicate listing, which is how a catalogue fills with orphans.
    await expect(page.locator('#name')).toBeEnabled()
    await expect(page.getByRole('button', { name: 'Değişiklikleri kaydet' })).toBeEnabled()
  })

  test('one seller cannot open another seller listing', async ({ page, request }) => {
    const owner = await createApprovedSeller(request, {
      companyName: 'Kavim Tekstil Anonim Şirketi',
      displayName: 'Kavim Tekstil',
    })

    const rival = await createApprovedSeller(request, {
      companyName: 'Meridyen Mobilya Anonim Şirketi',
      displayName: 'Meridyen Mobilya',
      operator: owner.operator,
    })

    const categories = await (await request.get(`${API}/api/v1/catalog/categories`)).json()
    const category = categories.data.find(
      (item: { slug: string }) => item.slug === TEST_CATEGORY_SLUG,
    )

    const created = await (await request.post(`${API}/api/v1/seller/products`, {
      headers: { Authorization: `Bearer ${owner.account.token}`, Accept: 'application/json' },
      data: { name: `Gizli Ürün ${Date.now()}`, primary_category_id: category.id },
    })).json()

    // The API is the boundary that matters; the UI simply reports what it says.
    const attempt = await request.get(`${API}/api/v1/seller/products/${created.data.id}`, {
      headers: { Authorization: `Bearer ${rival.account.token}`, Accept: 'application/json' },
    })

    expect(attempt.status()).toBe(403)

    await signIn(page, PORTAL, rival.account.email)
    await gotoInteractive(page, `${PORTAL}/products/${created.data.id}`)

    await expect(page.getByText(/yetki|bulunamadı/i).first()).toBeVisible()
  })
})
