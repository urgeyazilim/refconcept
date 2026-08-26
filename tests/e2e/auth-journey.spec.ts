import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { checkStable, fillStable } from './support/forms'
import { gotoHydrated, gotoInteractive, waitForHydration } from './support/hydration'
import { clearInbox, extractLink, waitForMessage } from './support/mailpit'

/**
 * The customer identity journey from 15_CRITICAL_E2E_SCENARIOS.md.
 *
 * Deliberately end-to-end: a real browser drives the real Nuxt app, which calls the
 * real API, which writes to PostgreSQL and queues a real notification that a real
 * worker delivers to a real SMTP server. The verification link is read back out of
 * that message. Nothing here is stubbed, so a break anywhere in the chain fails here.
 *
 * Fields are addressed by id rather than by label: several labels legitimately share
 * words ("E-posta" appears in the marketing consent text too), and a locator that
 * matches two controls fails for a reason that has nothing to do with the product.
 */

/** Unique per run so repeated runs never collide on the unique e-mail index. */
function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.floor(Math.random() * 1e4)}@refconcept.local`
}

const PASSWORD = 'E2eGucluParola2026'

/** Fills the registration form and submits it. */
async function submitRegistration(
  page: Page,
  email: string,
  { consent = true, firstName = '', lastName = '' } = {},
): Promise<void> {
  await gotoHydrated(page, '/auth/register')

  if (firstName) await fillStable(page, '#first_name', firstName)
  if (lastName) await fillStable(page, '#last_name', lastName)

  await fillStable(page, '#email', email)
  await fillStable(page, '#password', PASSWORD)
  await fillStable(page, '#password_confirmation', PASSWORD)

  if (consent) {
    await checkStable(page, '#privacy')
    await checkStable(page, '#terms')
  }

  await page.getByRole('button', { name: 'Hesabımı oluştur' }).click()

  if (consent) {
    await waitOutTheThrottle(page)
  }
}

/**
 * Waits out the registration rate limit rather than disabling it.
 *
 * Registration is throttled to a handful of attempts a minute per address, which is the
 * correct production behaviour and exactly what a suite that creates a dozen accounts
 * runs into. Raising the limit for tests would mean the suite no longer exercises the
 * configuration that ships, so instead the form is resubmitted after the window passes —
 * which is also what a real person would do, and it keeps the throttle real.
 *
 * The API helper in support/accounts.ts honours `Retry-After` for the same reason; a
 * browser cannot read that header, so the window is waited out in full.
 */
async function waitOutTheThrottle(page: Page): Promise<void> {
  const throttled = page.getByText(/Too Many Attempts|Çok fazla/i)
  const accepted = page.getByRole('heading', { name: 'E-postanızı kontrol edin' })

  for (let attempt = 0; attempt < 3; attempt++) {
    /*
     * Wait for the submission to resolve before judging it. Reading the page the instant
     * after the click saw neither notice — the response had not arrived yet — so a refusal
     * went unnoticed and the test failed fifteen seconds later on a confirmation that was
     * never coming, with the throttle notice sitting in the failure screenshot.
     */
    await expect(accepted.or(throttled)).toBeVisible({ timeout: 20_000 })

    if (await throttled.count() === 0) {
      return
    }

    // Five registrations a minute from one address, so the window has to pass in full.
    await page.waitForTimeout(61_000)
    await page.getByRole('button', { name: 'Hesabımı oluştur' }).click()
  }
}

async function signIn(page: Page, email: string, password: string, expectSuccess = true): Promise<void> {
  await gotoHydrated(page, '/auth/login')
  await fillStable(page, '#email', email)
  await fillStable(page, '#password', password)

  /*
   * The response is read, not merely awaited.
   *
   * A sign-in that fails leaves the browser on the login page with a one-line notice, and
   * every assertion after it then fails on a URL or a heading — fifteen seconds later,
   * with no hint of why. Watching the request means a refusal fails here, carrying the
   * status and the server's own words.
   */
  const [response] = await Promise.all([
    page.waitForResponse(res => res.url().includes('/api/v1/auth/login') && res.request().method() === 'POST'),
    page.getByRole('button', { name: 'Giriş yap' }).click(),
  ])

  // Two tests sign in wrongly on purpose; for them a refusal is the thing being asserted.
  if (expectSuccess && !response.ok()) {
    const body = await response.text().catch(() => '')

    throw new Error(`giriş reddedildi (${response.status()}): ${body.slice(0, 300)}`)
  }
}

/** Registers an account and follows the verification link out of the delivered mail. */
async function registerVerifiedAccount(page: Page, email: string): Promise<void> {
  await submitRegistration(page, email)
  await expect(page.getByRole('heading', { name: 'E-postanızı kontrol edin' })).toBeVisible()

  const mail = await waitForMessage(email, { subjectContains: 'doğrula' })
  await page.goto(extractLink(mail, '/auth/verify-email'))
  await waitForHydration(page)
  await expect(page.getByRole('heading', { name: 'E-postanız doğrulandı' })).toBeVisible()
}

test.describe('customer identity journey', () => {
  test('register, verify by e-mail, sign in, edit profile and add an address', async ({ page }) => {
    const email = uniqueEmail('journey')
    await clearInbox()

    // --- register ------------------------------------------------------------
    await submitRegistration(page, email, { firstName: 'Deniz', lastName: 'Yılmaz' })

    await expect(page.getByRole('heading', { name: 'E-postanızı kontrol edin' })).toBeVisible()
    await expect(page.getByText(email)).toBeVisible()

    // --- verify via the delivered e-mail -------------------------------------
    const mail = await waitForMessage(email, { subjectContains: 'doğrula' })
    await page.goto(extractLink(mail, '/auth/verify-email'))
    await waitForHydration(page)
    await expect(page.getByRole('heading', { name: 'E-postanız doğrulandı' })).toBeVisible()

    // --- sign in --------------------------------------------------------------
    await signIn(page, email, PASSWORD)

    await expect(page).toHaveURL(/\/account$/)
    await expect(page.getByRole('heading', { name: 'Deniz Yılmaz' })).toBeVisible()

    // A verified account must not see the "verify your address" warning.
    await expect(page.getByText('E-postanız doğrulanmadı')).toHaveCount(0)

    // --- edit the profile -----------------------------------------------------
    await fillStable(page, '#display_name', 'Deniz Y.')
    await page.getByRole('button', { name: 'Kaydet' }).click()
    await expect(page.getByText('Profiliniz güncellendi.')).toBeVisible()

    // --- add an address -------------------------------------------------------
    await gotoInteractive(page, '/account/addresses')
    await expect(page.getByText('Henüz kayıtlı adresiniz yok.')).toBeVisible()

    await page.getByRole('button', { name: 'İlk adresinizi ekleyin' }).click()
    await fillStable(page, '#label', 'Ev')
    await fillStable(page, '#recipient_name', 'Deniz Yılmaz')
    await fillStable(page, '#city', 'İstanbul')
    await fillStable(page, '#district', 'Kadıköy')
    await fillStable(page, '#address_line1', 'Bağdat Caddesi 100')
    await page.getByRole('button', { name: 'Adresi kaydet' }).click()

    await expect(page.getByText('Bağdat Caddesi 100')).toBeVisible()

    // The first address becomes the default for both purposes.
    await expect(page.getByText('Teslimat', { exact: true })).toBeVisible()
    await expect(page.getByText('Fatura', { exact: true })).toBeVisible()

    // --- sign out -------------------------------------------------------------
    await page.getByRole('button', { name: 'Çıkış yap' }).first().click()
    await expect(page).toHaveURL(/localhost:3000\/$/)

    // The session is really gone, not just hidden in the UI.
    await gotoHydrated(page, '/account')
    await expect(page).toHaveURL(/\/auth\/login/)
  })

  test('an unverified account can sign in but cannot use the address book', async ({ page }) => {
    const email = uniqueEmail('unverified')
    await clearInbox()

    await submitRegistration(page, email)
    await expect(page.getByRole('heading', { name: 'E-postanızı kontrol edin' })).toBeVisible()

    await signIn(page, email, PASSWORD)

    await expect(page).toHaveURL(/\/account$/)
    await expect(page.getByText('E-postanız doğrulanmadı')).toBeVisible()

    // The client-side gate mirrors the API's 403 so the user gets a route they can act on.
    await gotoInteractive(page, '/account/addresses')
    await expect(page).toHaveURL(/\/auth\/verify-email/)
  })

  test('registration refuses to proceed without the mandatory consents', async ({ page }) => {
    const email = uniqueEmail('noconsent')

    await submitRegistration(page, email, { consent: false })

    await expect(page.getByText('Gizlilik bildirimini onaylamanız gerekiyor.')).toBeVisible()
    await expect(page.getByText('Kullanım koşullarını onaylamanız gerekiyor.')).toBeVisible()

    // Still on the form, no account created.
    await expect(page.getByRole('button', { name: 'Hesabımı oluştur' })).toBeVisible()
  })

  test('a wrong password is rejected without revealing whether the account exists', async ({ page }) => {
    await signIn(page, 'admin@refconcept.local', 'kesinlikle-yanlis', false)
    const knownAccountError = await page.getByText(/hatalı/).textContent()

    await signIn(page, uniqueEmail('nobody'), 'kesinlikle-yanlis', false)
    const unknownAccountError = await page.getByText(/hatalı/).textContent()

    // Any difference here would turn the login form into an account-enumeration oracle.
    expect(unknownAccountError).toBe(knownAccountError)
  })

  test('password reset delivers a working link and invalidates the old password', async ({ page }) => {
    const email = uniqueEmail('reset')
    const newPassword = 'YepyeniE2eParola2026'
    await clearInbox()

    await registerVerifiedAccount(page, email)

    // --- request the reset ----------------------------------------------------
    await clearInbox()
    await gotoHydrated(page, '/auth/forgot-password')
    await fillStable(page, '#email', email)
    await page.getByRole('button', { name: 'Sıfırlama bağlantısı gönder' }).click()
    await expect(page.getByRole('heading', { name: 'E-postanızı kontrol edin' })).toBeVisible()

    const resetMail = await waitForMessage(email, { subjectContains: 'Parola' })
    await page.goto(extractLink(resetMail, '/auth/reset-password'))
    await waitForHydration(page)

    await fillStable(page, '#password', newPassword)
    await fillStable(page, '#password_confirmation', newPassword)
    await page.getByRole('button', { name: 'Parolamı güncelle' }).click()

    await expect(page).toHaveURL(/\/auth\/login/)
    await expect(page.getByText('Parolanız güncellendi.')).toBeVisible()

    // --- the old password is dead, the new one works --------------------------
    await signIn(page, email, PASSWORD, false)
    await expect(page.getByText(/hatalı/)).toBeVisible()

    await signIn(page, email, newPassword)
    await expect(page).toHaveURL(/\/account$/)
  })
})
