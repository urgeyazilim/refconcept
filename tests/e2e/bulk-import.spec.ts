import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { DEFAULT_PASSWORD } from './support/accounts'
import { createApprovedSeller } from './support/sellers'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'

/**
 * The Phase 4 gate: a spreadsheet becomes a catalogue, and a machine keeps it current.
 *
 * The property under test is not "CSV parsing works" — that has unit tests. It is that
 * a seller can see what an import will do *before* it happens, that one malformed line
 * does not take the rest with it, and that their own systems can then push stock and
 * prices without a person in the loop.
 *
 * The file is written the way Turkish Excel writes one: semicolons, comma decimals, a
 * byte-order mark. A test using a tidy comma-separated ASCII file would pass while the
 * real thing failed on the first upload.
 */

const PORTAL = process.env.E2E_SELLER_PORTAL_URL ?? 'http://localhost:3001'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

test.describe.configure({ timeout: 300_000 })

async function signIn(page: Page, base: string, email: string): Promise<void> {
  await page.context().clearCookies()
  await page.goto(`${base}/auth/login`)
  await waitForHydration(page)

  await fillStable(page, '#email', email)
  await fillStable(page, '#password', DEFAULT_PASSWORD)
  await page.getByRole('button', { name: 'Giriş yap' }).click()

  await expect(page).toHaveURL(new RegExp(`${base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/?$`))
}

/** A Turkish-Excel-shaped CSV: BOM, semicolons, comma decimals. */
function turkishCsv(rows: string[][]): Buffer {
  const header = 'SKU Kodu;Ürün Adı;Kategori;Marka;Liste Fiyatı;KDV;Stok;Genişlik;Derinlik;Renk;Malzeme'
  const body = rows.map(row => row.join(';')).join('\r\n')

  return Buffer.from(`\uFEFF${header}\r\n${body}\r\n`, 'utf8')
}

test.describe('bulk import', () => {
  test('a spreadsheet becomes catalogue rows, but only after the seller sees the preview', async ({ page, request }) => {
    const suffix = Date.now()

    const seller = await createApprovedSeller(request, {
      companyName: 'Atlas Mobilya Anonim Şirketi',
      displayName: 'Atlas Mobilya',
    })

    await signIn(page, PORTAL, seller.account.email)

    await page.getByRole('link', { name: 'Toplu aktarma' }).first().click()
    await waitForHydration(page)

    await expect(page.getByRole('heading', { name: 'Henüz dosya yüklemediniz' })).toBeVisible()

    // Two good rows and one with a price that is not a number and a category that
    // does not exist.
    await page.locator('input[type="file"]').setInputFiles({
      name: 'urunler.csv',
      mimeType: 'text/csv',
      buffer: turkishCsv([
        [`IMP-${suffix}-1`, 'Bouclé Kanepe', 'kanepe', 'Arden', '48.900,00', '20', '6', '2200', '950', 'Krem', 'Bouclé'],
        [`IMP-${suffix}-2`, 'Meşe Sehpa', 'sehpa', 'Nordhem', '12.400,00', '20', '8', '900', '900', 'Meşe', 'Masif Meşe'],
        [`IMP-${suffix}-3`, 'X', 'boyle-bir-kategori-yok', 'Arden', 'bilinmiyor', '20', '1', '100', '100', 'Krem', 'Keten'],
      ]),
    })

    // --- the mapping was guessed from Turkish headers -------------------------
    await expect(page.getByRole('heading', { name: 'Sütun eşleştirme' })).toBeVisible()
    await expect(page.locator('select').first()).toHaveValue('sku')

    // --- the dry run reports without writing ----------------------------------
    await page.getByRole('button', { name: 'Ön izlemeyi çalıştır' }).click()

    await expect(page.getByText('Ön izleme tamamlandı.')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Ön izleme sonucu' })).toBeVisible()

    // Nothing is in the catalogue yet. This is the assertion the whole three-step
    // shape exists for.
    const beforeCommit = await request.get(`${API}/api/v1/seller/products?search=Bouclé Kanepe`, {
      headers: { Authorization: `Bearer ${seller.account.token}`, Accept: 'application/json' },
    })

    expect((await beforeCommit.json()).data).toHaveLength(0)

    // --- the bad row is named, with its line number ---------------------------
    await expect(page.getByText('Satır 4')).toBeVisible()
    // The slug appears twice — in the error message and in the echoed raw cells —
    // which is the point: the seller sees both what was wrong and what they wrote.
    await expect(page.getByText(/boyle-bir-kategori-yok/).first()).toBeVisible()

    // --- committing applies the good rows and skips the bad one ---------------
    await page.getByRole('button', { name: /2 satırı aktar/ }).click()

    await expect(page.getByRole('heading', { name: 'Aktarma tamamlandı' })).toBeVisible()

    const afterCommit = await request.get(`${API}/api/v1/seller/products`, {
      headers: { Authorization: `Bearer ${seller.account.token}`, Accept: 'application/json' },
    })

    const names = (await afterCommit.json()).data.map((product: { name: string }) => product.name).sort()

    expect(names).toEqual(['Bouclé Kanepe', 'Meşe Sehpa'])

    // --- the Turkish decimal survived the round trip ---------------------------
    await gotoInteractive(page, `${PORTAL}/prices`)

    // "48.900,00" is 48900 lira, not 48.9. A misread decimal here would put a sofa on
    // sale for the price of a cushion.
    await expect(page.getByText('48.900,00 ₺').first()).toBeVisible()

    // --- stock arrived through the ledger --------------------------------------
    await gotoInteractive(page, `${PORTAL}/stock`)

    const stockRow = page.getByRole('row', { name: new RegExp('Bouclé Kanepe') })
    await expect(stockRow).toContainText('6')
  })

  test('a seller can reprice in bulk and see where the change came from', async ({ page, request }) => {
    const suffix = Date.now()

    const seller = await createApprovedSeller(request, {
      companyName: 'Nova Yaşam Anonim Şirketi',
      displayName: 'Nova Yaşam',
    })

    const headers = { Authorization: `Bearer ${seller.account.token}`, Accept: 'application/json' }

    // Built through the API: this test is about repricing, not about the import form.
    const categories = await (await request.get(`${API}/api/v1/catalog/categories`)).json()
    const category = categories.data.find((item: { slug: string }) => item.slug === 'kanepe')

    const product = await (await request.post(`${API}/api/v1/seller/products`, {
      headers,
      data: { name: `Fiyat Testi ${suffix}`, primary_category_id: category.id },
    })).json()

    await request.post(`${API}/api/v1/seller/products/${product.data.id}/skus`, {
      headers,
      data: { sku: `PRC-${suffix}`, list_price_minor: 100_000, stock_quantity: 5 },
    })

    await signIn(page, PORTAL, seller.account.email)
    await gotoInteractive(page, `${PORTAL}/prices`)

    const listInput = page.getByLabel(`PRC-${suffix} liste fiyatı`)
    // The editable field carries a bare number without grouping separators — grouping
    // dots in an input a person is about to retype are noise, not help. 100.000 minor
    // units is a thousand lira, which is the round trip the money design exists for.
    await expect(listInput).toHaveValue('1000,00')

    await listInput.fill('1.250,00')
    await page.getByLabel(`PRC-${suffix} indirimli fiyatı`).fill('999,00')

    await page.getByRole('button', { name: /1 fiyatı kaydet/ }).click()
    await expect(page.getByText(/1 fiyat güncellendi/)).toBeVisible()

    // --- the history says what changed and who changed it ----------------------
    await page.getByRole('button', { name: 'Geçmiş' }).first().click()

    await expect(page.getByRole('heading', { name: 'Fiyat geçmişi' })).toBeVisible()
    await expect(page.getByText('1.000,00 ₺').first()).toBeVisible()
    await expect(page.getByText('1.250,00 ₺').first()).toBeVisible()
    await expect(page.getByText('Elle').first()).toBeVisible()
  })

  test('a seller system can push stock and prices with a scoped credential', async ({ page, request }) => {
    const suffix = Date.now()

    const seller = await createApprovedSeller(request, {
      companyName: 'Kavim Tekstil Anonim Şirketi',
      displayName: 'Kavim Tekstil',
    })

    const headers = { Authorization: `Bearer ${seller.account.token}`, Accept: 'application/json' }

    const categories = await (await request.get(`${API}/api/v1/catalog/categories`)).json()
    const category = categories.data.find((item: { slug: string }) => item.slug === 'hali')

    const product = await (await request.post(`${API}/api/v1/seller/products`, {
      headers,
      data: { name: `Halı ${suffix}`, primary_category_id: category.id },
    })).json()

    const skuCode = `API-${suffix}`

    await request.post(`${API}/api/v1/seller/products/${product.data.id}/skus`, {
      headers,
      data: { sku: skuCode, list_price_minor: 2_760_000, stock_quantity: 3 },
    })

    // --- the seller issues a credential from the portal ------------------------
    await signIn(page, PORTAL, seller.account.email)
    await gotoInteractive(page, `${PORTAL}/integrations`)

    await page.getByRole('button', { name: 'Kimlik bilgisi oluştur' }).click()

    await fillStable(page, '#name', 'Depo yazılımı')
    await page.getByRole('button', { name: 'Stok okuma' }).click()
    await page.getByRole('button', { name: 'Stok yazma' }).click()
    await page.getByRole('button', { name: 'Oluştur' }).click()

    await expect(page.getByRole('heading', { name: /Depo yazılımı oluşturuldu/ })).toBeVisible()

    // The secret is on screen exactly once. Reading it here is the only chance
    // anything — a person or a test — ever gets.
    const keyId = (await page.locator('code').first().textContent())!.trim()
    const secret = (await page.locator('code').nth(1).textContent())!.trim()

    expect(keyId).toMatch(/^rck_/)
    expect(secret).toMatch(/^rcs_/)

    const partnerHeaders = {
      'X-RefConcept-Key': keyId,
      'X-RefConcept-Secret': secret,
      'Accept': 'application/json',
    }

    // --- the machine sets stock as a count -------------------------------------
    const pushed = await request.post(`${API}/api/v1/partner/stock`, {
      headers: partnerHeaders,
      data: { items: [{ sku: skuCode, quantity: 11 }] },
    })

    expect(pushed.ok()).toBeTruthy()
    expect((await pushed.json()).meta.accepted).toBe(1)

    // --- and cannot touch prices, because it was not given that scope ----------
    const refused = await request.post(`${API}/api/v1/partner/prices`, {
      headers: partnerHeaders,
      data: { items: [{ sku: skuCode, list_price_minor: 1 }] },
    })

    expect(refused.status()).toBe(403)

    // --- the seller sees the new stock and the request that set it -------------
    await gotoInteractive(page, `${PORTAL}/stock`)
    await expect(page.getByRole('row', { name: new RegExp(`Halı ${suffix}`) })).toContainText('11')

    await gotoInteractive(page, `${PORTAL}/integrations`)
    await page.getByRole('button', { name: 'İstekler' }).first().click()

    await expect(page.getByText('api/v1/partner/stock').first()).toBeVisible()

    // --- revoking kills it immediately ------------------------------------------
    page.once('dialog', dialog => dialog.accept('Entegrasyon kapatıldı.'))

    await page.getByRole('button', { name: 'Kapat' }).click()
    await page.getByRole('button', { name: 'İptal et' }).click()

    await expect.poll(async () => {
      const after = await request.get(`${API}/api/v1/partner/stock`, { headers: partnerHeaders })

      return after.status()
    }).toBe(401)
  })
})
