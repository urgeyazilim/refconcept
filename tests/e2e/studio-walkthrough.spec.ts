import { expect, test } from '@playwright/test'
import type { APIRequestContext, Page } from '@playwright/test'
import { completeBrief } from './support/brief'
import { createVerifiedAccount, grantPlatformRole } from './support/accounts'
import { gotoInteractive, waitForHydration } from './support/hydration'
import { pngBuffer } from './support/sellers'
import { fillStable } from './support/forms'
import { signInThrough } from './support/signin'

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

/**
 * A picture of every screen the customer is walked through, at one size.
 *
 * Not an assertion suite. The product owner asked for the screens side by side so the
 * inconsistencies between them can be seen rather than argued about — where the page starts,
 * how tall the chrome is, whether the guide is a band or a column, whether anything is cut
 * off at the bottom of a screen that is supposed to be a fixed one. Each step writes one
 * viewport-sized frame (not fullPage: clipping is the thing being looked for) into
 * test-results/walkthrough.
 *
 * It fails loudly if a step cannot be reached, because a walk that quietly skipped the
 * render screen would produce a gallery with a hole in it and nobody would notice.
 */
const SHOT = 'test-results/walkthrough'

test.use({ viewport: { width: 1440, height: 900 } })

/**
 * What each frame measured, so the comparison is arithmetic rather than impression.
 *
 * Printed as one table at the end of the walk: where the page starts, how far past the
 * bottom of the screen it goes (the studio is meant to be a fixed screen and scroll by
 * nothing), and how tall the chrome above the first panel is.
 */
const measured: Array<{ frame: string, left: number, overflow: number, chrome: number, strip: number }> = []

/** One frame, named so the gallery sorts into walking order. */
async function frame(page: Page, at: number, name: string): Promise<void> {
  // Let the step's fade settle; every screen in the studio crossfades, and a frame taken
  // halfway through one shows a washed-out page that is not what anybody sees.
  await page.waitForTimeout(900)
  await page.screenshot({
    path: `${SHOT}/${String(at).padStart(2, '0')}-${name}.png`,
    // The room keeps drawing itself, so a shutter that waits for the page to go still waits
    // for ever. Frozen at the first frame, which is also what makes two runs comparable.
    animations: 'disabled',
    timeout: 60_000,
  })

  const shape = await page.evaluate(() => {
    // The page's own frame, and the first thing a reader sees inside it. Measuring whatever
    // happened to be main's first child measured a different kind of element per page.
    const frame = document.querySelector('main .rc-page') ?? document.querySelector('main .rc-container')
    const first = frame?.firstElementChild ?? null
    const box = first?.getBoundingClientRect()

    // How wide the step strip gets: under about 1040 it gives up the ten step names.
    const strip = document.querySelector('nav[aria-label="Oda stüdyosu adımları"]')

    return {
      left: box ? Math.round(box.left) : -1,
      overflow: Math.max(0, document.documentElement.scrollHeight - window.innerHeight),
      chrome: box ? Math.round(box.top) : -1,
      strip: strip ? Math.round(strip.getBoundingClientRect().width) : 0,
    }
  })

  measured.push({ frame: `${String(at).padStart(2, '0')}-${name}`, ...shape })
}

/** Credits, through the console an operator would use: there is no customer route. */
async function grantCredits(request: APIRequestContext, customerToken: string): Promise<void> {
  const operator = await createVerifiedAccount('walkthrough-operator')
  await grantPlatformRole(operator.email, 'super-admin')

  const me = await request.get(`${API}/api/v1/auth/me`, {
    headers: { Authorization: `Bearer ${customerToken}`, Accept: 'application/json' },
  })

  const customerId = (await me.json()).data.id

  const granted = await request.post(`${API}/api/v1/admin/credits/wallets/${customerId}/adjust`, {
    headers: { Authorization: `Bearer ${operator.token}`, Accept: 'application/json' },
    data: { delta: 80, reason: 'E2E ekran yürüyüşü için bakiye.' },
  })

  expect(granted.ok()).toBeTruthy()
}

test.describe('studio walkthrough', () => {
  test.setTimeout(600_000)

  // The table is the point of the walk: the frames show it, these numbers say it.
  test.afterAll(() => {
    console.log('kare                        sol   kayma   tepe  serit')

    for (const row of measured) {
      console.log(`${row.frame.padEnd(26)} ${String(row.left).padStart(4)} ${String(row.overflow).padStart(6)} ${String(row.chrome).padStart(6)} ${String(row.strip).padStart(6)}`)
    }
  })

  test('one picture of every step, at one size', async ({ page, request }) => {
    const account = await createVerifiedAccount('walkthrough-customer')
    const headers = { Authorization: `Bearer ${account.token}`, Accept: 'application/json' }

    await grantCredits(request, account.token)

    const project = await request.post(`${API}/api/v1/projects`, {
      headers,
      data: { name: `Yürüyüş ${Date.now()}`, project_type: 'home' },
    })

    const projectId = (await project.json()).data.id

    const room = await request.post(`${API}/api/v1/projects/${projectId}/rooms`, {
      headers,
      data: { name: 'Salon', room_type: 'living_room' },
    })

    const roomId = (await room.json()).data.id

    await signInThrough(page, STOREFRONT, account.email, /\/account$/)

    // --- the way in ------------------------------------------------------------------
    await gotoInteractive(page, `${STOREFRONT}/projects`)
    await frame(page, 1, 'projeler')

    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}`)
    await frame(page, 2, 'proje')

    // --- step 1: the photograph -------------------------------------------------------
    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}/rooms/${roomId}`)
    await expect(page.getByText('Hadi odanın fotoğrafını çekelim.')).toBeVisible()
    await frame(page, 3, 'adim1-fotograf-bos')

    const chooser = page.waitForEvent('filechooser')

    await page.getByRole('button', { name: 'Fotoğraf ekle' }).click()
    await (await chooser).setFiles({ name: 'salon.png', mimeType: 'image/png', buffer: pngBuffer(1280, 960) })

    const strip = page.getByRole('navigation', { name: 'Oda stüdyosu adımları' })

    await expect(strip.getByRole('button', { name: /✓\s*Fotoğraf/ })).toBeVisible({ timeout: 120_000 })
    await frame(page, 4, 'adim1-fotograf-yuklendi')

    // --- step 2: the furniture --------------------------------------------------------
    await strip.getByRole('button', { name: /Eşyalar/ }).click()
    await frame(page, 5, 'adim2-esyalar')

    // --- step 3: the room -------------------------------------------------------------
    await strip.getByRole('button', { name: /Oda/ }).click()
    await frame(page, 6, 'adim3-oda')

    const correct = page.getByRole('button', { name: 'Düzelt' }).first()

    if (await correct.isVisible().catch(() => false)) {
      await correct.click()
      await frame(page, 7, 'adim3-oda-olcu-formu')
      await page.getByRole('button', { name: 'Vazgeç' }).first().click().catch(() => {})
    }

    /*
     * The size agreed to, which is what the plan draws from.
     *
     * Either answer to the question the step asks: "Evet, doğru" when the reading proposed
     * one, the form when it did not. A walk that skipped it reached step six and found a
     * screen that cannot draw a room nobody has measured.
     */
    const yes = page.getByRole('button', { name: 'Evet, doğru' }).first()

    if (await yes.isVisible().catch(() => false)) {
      await yes.click()
    }
    else {
      await fillStable(page, '#width', '420')
      await fillStable(page, '#length', '560')
      await fillStable(page, '#height', '270')
      await page.locator('#quality').selectOption('manual')
      await page.getByRole('button', { name: 'Ölçüleri kaydet' }).click()
    }

    await expect(strip.getByRole('button', { name: /✓\s*Oda/ })).toBeVisible({ timeout: 60_000 })

    // --- step 4: the questions --------------------------------------------------------
    await strip.getByRole('button', { name: /İstekler/ }).click()
    await frame(page, 8, 'adim4-istekler')

    await completeBrief(page)

    // --- step 5: the design -----------------------------------------------------------
    await expect(page.getByRole('heading', { level: 1, name: /Salon tasarımı/ })).toBeVisible()
    await frame(page, 9, 'adim5-tasarim-uretiliyor')

    await expect(page.getByText('Hazır', { exact: true }).first()).toBeVisible({ timeout: 180_000 })
    await frame(page, 10, 'adim5-tasarim-hazir')

    const designUrl = page.url()

    // --- steps 6 and 7: the 3D room and saving it --------------------------------------
    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}/rooms/${roomId}/plan`)
    // Pictured before it is asserted: a plan that cannot draw is itself worth a frame.
    await frame(page, 11, 'adim6-3b')
    await expect(page.locator('canvas')).toBeVisible({ timeout: 60_000 })

    // The arrangement the design asked for, which is what step 7 saves.
    await page.waitForTimeout(6_000)
    await frame(page, 12, 'adim7-kayit')

    // --- steps 8, 9 and 10: render, the tour, the basket -------------------------------
    await gotoInteractive(page, designUrl)
    await frame(page, 13, 'adim8-render')

    for (const [at, hash, name] of [[14, '#render', 'adim8-render-panel'], [15, '#video', 'adim9-360'], [16, '#alisveris', 'adim10-satin-al']] as const) {
      await gotoInteractive(page, `${designUrl.split('#')[0]}${hash}`)
      await waitForHydration(page)
      await frame(page, at, name)
    }
  })

  test('the frame is the same on every page', async ({ page }) => {
    const account = await createVerifiedAccount('walkthrough-frame')

    await signInThrough(page, STOREFRONT, account.email, /\/account$/)

    const pages: Array<[number, string, string]> = [
      [20, '/', 'anasayfa'],
      [21, '/catalog', 'katalog'],
      [22, '/favorites', 'favoriler'],
      [23, '/cart', 'sepet'],
      [24, '/account', 'hesap'],
      [25, '/account/credits', 'krediler'],
      [26, '/projects', 'projeler'],
    ]

    for (const [at, path, name] of pages) {
      await gotoInteractive(page, `${STOREFRONT}${path}`)
      await frame(page, at, name)
    }
  })
})
