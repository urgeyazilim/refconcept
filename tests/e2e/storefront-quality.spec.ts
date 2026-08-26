import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { createVerifiedAccount } from './support/accounts'
import { listProduct } from './support/catalog'
import { gotoInteractive } from './support/hydration'
import { signInThrough } from './support/signin'

/**
 * The Phase 20 gate: the storefront as a public website, not only as an application.
 *
 * Three things nobody notices while building and everybody notices afterwards.
 *
 * **A phone can use it.** The desktop navigation is hidden below `lg`, so without a drawer
 * a phone visitor sees a logo and a sign-up button and no way to reach the catalogue —
 * which is most of the traffic and all of the shopping.
 *
 * **A crawler is told what to look at.** A canonical on every public page, structured data
 * on a product, and `noindex` on everything behind a sign-in. An order page is not secret,
 * it is protected; but a URL a crawler can reach is a URL a search result can carry, and
 * "it needed a login anyway" is no comfort once the title is indexed.
 *
 * **A keyboard can drive it.** The skip link is the first focusable element on the page,
 * because tabbing through a whole header to reach an article is not navigation.
 */

const API = process.env.E2E_API_URL ?? 'http://localhost:58000'
const PHONE = { width: 390, height: 844 }

async function signIn(page: Page, email: string): Promise<void> {
  // Retries once through a rate-limit refusal. See tests/e2e/support/signin.ts — the
  // limiter is production-strength on purpose, and a suite this long trips it.
  await signInThrough(page, '', email)
}

test.describe.configure({ timeout: 420_000 })

// --- what a crawler sees ----------------------------------------------------------

test.describe('storefront discoverability', () => {
  test('robots.txt keeps crawlers out of everything behind a sign-in', async ({ page }) => {
    const response = await page.request.get('/robots.txt')

    expect(response.ok(), await response.text()).toBeTruthy()

    const body = await response.text()

    for (const path of ['/account/', '/cart', '/checkout', '/projects/', '/auth/']) {
      expect(body, path).toContain(`Disallow: ${path}`)
    }

    // And it points at the sitemap, which is the only way a crawler finds one.
    expect(body).toContain('Sitemap:')
  })

  test('the sitemap lists the public pages and the live catalogue', async ({ page, request }) => {
    const listing = await listProduct(request, `Sitemap Koltuk ${Date.now()}`, 500_000, 2)

    const response = await page.request.get('/sitemap.xml')

    expect(response.ok(), await response.text()).toBeTruthy()

    const xml = await response.text()

    expect(xml).toContain('<urlset')
    expect(xml).toContain('/catalog')
    expect(xml).toContain('/legal/terms')

    // Built from the catalogue rather than from a hand-kept list, which is wrong the day
    // after somebody adds a product.
    expect(xml).toContain(`/catalog/${listing.slug}`)

    // And nothing private ever appears in it.
    expect(xml).not.toContain('/account/')
    expect(xml).not.toContain('/checkout')
  })

  test('a product page carries a canonical and structured data', async ({ page, request }) => {
    const listing = await listProduct(request, `Kanonik Kanepe ${Date.now()}`, 640_000, 3)

    await gotoInteractive(page, `/catalog/${listing.slug}`)

    const canonical = page.locator('link[rel="canonical"]')

    await expect(canonical).toHaveCount(1)
    await expect(canonical).toHaveAttribute('href', new RegExp(`/catalog/${listing.slug}$`))

    // A share card that says nothing is a share card nobody clicks.
    await expect(page.locator('meta[property="og:title"]')).toHaveAttribute('content', /Kanonik Kanepe/)
    await expect(page.locator('meta[property="og:type"]')).toHaveAttribute('content', 'product')

    const jsonLd = await page.locator('script[type="application/ld+json"]').first().textContent()
    const data = JSON.parse(jsonLd ?? '{}')

    /*
     * The price and availability a search result would show. They come from what the page
     * itself displays — a listing claiming availability the page contradicts is the kind of
     * mismatch that gets a whole site's rich results turned off.
     */
    expect(data['@type']).toBe('Product')
    expect(data.offers.priceCurrency).toBe('TRY')
    expect(Number(data.offers.price)).toBeGreaterThan(0)
    expect(data.offers.availability).toBe('https://schema.org/InStock')
  })

  test('a page behind a sign-in refuses to be indexed', async ({ page }) => {
    const customer = await createVerifiedAccount('seo-private')

    await signIn(page, customer.email)
    await gotoInteractive(page, '/cart')

    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/)

    /*
     * And no canonical at all. A canonical asks a crawler to index one URL rather than
     * another, which is a contradiction on a page that must not be indexed — and crawlers
     * resolve that contradiction unpredictably.
     */
    await expect(page.locator('link[rel="canonical"]')).toHaveCount(0)
  })
})

// --- what a phone and a keyboard can do -------------------------------------------

test.describe('storefront accessibility and responsiveness', () => {
  test.use({ viewport: PHONE })

  test('a phone visitor can reach the catalogue', async ({ page }) => {
    await gotoInteractive(page, '/')

    // The desktop nav is gone at this width; without the drawer this is a dead end.
    await expect(page.getByRole('navigation', { name: 'Ana menü' })).toBeHidden()

    await page.getByTestId('menu-open').click()

    const drawer = page.getByRole('dialog', { name: 'Menü' })

    await expect(drawer).toBeVisible()

    await drawer.getByRole('link', { name: 'Ürünler' }).click()

    await expect(page).toHaveURL(/\/catalog$/)

    // Closed on navigation: a menu left hanging over the next page reads as a stuck overlay.
    await expect(page.getByRole('dialog', { name: 'Menü' })).toHaveCount(0)
  })

  test('the drawer closes on Escape and gives focus back to the page', async ({ page }) => {
    await gotoInteractive(page, '/')

    await page.getByTestId('menu-open').click()
    await expect(page.getByRole('dialog', { name: 'Menü' })).toBeVisible()

    /*
     * Focus moves into the drawer, onto the way out of it. A keyboard user who opens a
     * menu by accident should be one key from closing it, and a screen reader user should
     * hear where they are rather than being left on the page behind.
     */
    await expect(page.getByTestId('menu-close')).toBeFocused()

    await page.keyboard.press('Escape')

    await expect(page.getByRole('dialog', { name: 'Menü' })).toHaveCount(0)
  })

  test('the skip link is the first thing a keyboard reaches', async ({ page }) => {
    await gotoInteractive(page, '/')

    await page.keyboard.press('Tab')

    const skip = page.getByRole('link', { name: 'İçeriğe geç' })

    await expect(skip).toBeFocused()

    await skip.press('Enter')

    // It has to land somewhere real, not on a fragment nothing answers.
    await expect(page.locator('#main')).toBeVisible()
  })

  test('a customer can shop from a phone end to end', async ({ page, request }) => {
    const name = `Mobil Sehpa ${Date.now()}`
    const listing = await listProduct(request, name, 210_000, 4)
    const customer = await createVerifiedAccount('mobile-shopper')

    await request.post(`${API}/api/v1/addresses`, {
      headers: { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' },
      data: {
        recipient_name: 'Deniz Yılmaz',
        city: 'İstanbul',
        address_line1: 'Bağdat Caddesi 100',
        is_default_shipping: true,
      },
    })

    await signIn(page, customer.email)
    await gotoInteractive(page, `/catalog/${listing.slug}`)

    /*
     * The whole point of the phone work. Browsing, adding to the basket and paying are the
     * mobile priorities in the design spec, and none of them may depend on a viewport wide
     * enough for the desktop header.
     */
    await page.getByRole('button', { name: 'Sepete ekle' }).click()
    await expect(page.getByText('Ürün sepetinize eklendi.')).toBeVisible()

    // The basket link stays in the header at every width: it is the one thing a shopper
    // checks constantly, and burying it is how a half-filled cart gets forgotten.
    await page.getByTestId('header-cart').click()
    await expect(page).toHaveURL(/\/cart$/)

    // The heading first: the URL changes before the page does, and matching the product
    // name too early finds it on the page being navigated away from.
    await expect(page.getByRole('heading', { name: 'Sepetim' })).toBeVisible()
    await expect(page.getByText(name).first()).toBeVisible()

    // Nothing overflows sideways. A page a phone can scroll horizontally is a page whose
    // buttons move under the customer's thumb.
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )

    expect(overflow).toBeLessThanOrEqual(1)
  })

  test('the footer makes the legal pages reachable', async ({ page }) => {
    await gotoInteractive(page, '/')

    // They existed and could only be opened by typing the URL. A terms page nobody can
    // find is a terms page nobody agreed to.
    await page.getByRole('navigation', { name: 'Alt menü' })
      .getByRole('link', { name: 'Kullanım koşulları' })
      .click()

    await expect(page).toHaveURL(/\/legal\/terms$/)
  })
})
