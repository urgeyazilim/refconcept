import { expect, test } from '@playwright/test'
import { createVerifiedAccount } from './support/accounts'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'
import { pngBuffer } from './support/sellers'
import { signInThrough } from './support/signin'

/**
 * The studio's step strip says where the customer is.
 *
 * On a room with nothing in it the first step is lit; once a photograph is up and the size
 * is confirmed, those steps carry a tick; on the plan the strip knows the size was agreed
 * and lights "3B". The strip is the one part of the studio that is on every screen,
 * so this is the one journey that reads it.
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

test.describe.configure({ timeout: 300_000 })

test.describe('studio stepper', () => {
  test('the strip ticks the steps a room has been through and points at the next', async ({ page, request }) => {
    const account = await createVerifiedAccount('stepper-customer')
    const headers = { Authorization: `Bearer ${account.token}`, Accept: 'application/json' }

    const project = await request.post(`${API}/api/v1/projects`, { headers, data: { name: `Stüdyo ${Date.now()}` } })
    const projectId = (await project.json()).data.id
    const room = await request.post(`${API}/api/v1/projects/${projectId}/rooms`, { headers, data: { name: 'Salon', room_type: 'living_room' } })
    const roomId = (await room.json()).data.id

    await signInThrough(page, STOREFRONT, account.email, /\/account$/)
    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}/rooms/${roomId}`)
    await waitForHydration(page)

    const strip = page.getByRole('navigation', { name: 'Oda stüdyosu adımları' })

    await expect(strip).toBeVisible()
    await expect(strip.getByRole('button', { name: /1\s*Fotoğraf/ })).toHaveAttribute('aria-current', 'step')
    // The next step is on the strip, quiet and numbered; the 'Sıradaki' hint only shows when there is room for it.
    await expect(strip.getByText(/2\s*Eşyalar/)).toBeVisible()

    // --- a photograph, then the size confirmed on the Oda step ----------------------
    /*
     * Through the button, not into the hidden input.
     *
     * Everything on this screen is server-rendered, so waiting for text proves nothing about
     * whether Vue has bound the input's change handler yet — and a file put into an unbound
     * input is swallowed without a word. The chooser only opens once the button's own handler
     * is live, which is the guarantee the test needs.
     */
    const chooser = page.waitForEvent('filechooser')

    await page.getByRole('button', { name: 'Fotoğraf ekle' }).click()
    await (await chooser).setFiles({
      name: 'salon.png',
      mimeType: 'image/png',
      buffer: pngBuffer(1024, 768),
    })
    // The guide moves on by itself once the reading lands, so the photo panel may already
    // have given way; what matters is that the photograph was taken.
    await expect(strip.getByRole('button', { name: /✓\s*Fotoğraf/ })).toBeVisible({ timeout: 120_000 })

    // The size lives on the Oda step; the strip opens it without waiting for the reading.
    await strip.getByRole('button', { name: /Oda/ }).click()
    // The reading may have landed and proposed a size; open the form deliberately.
    const correct = page.getByRole('button', { name: 'Düzelt' }).first()

    if (await correct.isVisible().catch(() => false)) await correct.click()

    await fillStable(page, '#width', '420')
    await fillStable(page, '#length', '560')
    await fillStable(page, '#height', '270')
    await page.locator('#quality').selectOption('manual')
    await page.getByRole('button', { name: 'Ölçüleri kaydet' }).click()
    await expect(page.getByText('23.52 m²').first()).toBeVisible()

    // Fotoğraf and Oda are ticked; the guide has moved on to what it asks next.
    await expect(strip.getByRole('button', { name: /✓\s*Fotoğraf/ })).toBeVisible()
    await expect(strip.getByRole('button', { name: /✓\s*Oda/ })).toBeVisible()

    /*
     * Back a step, from the strip.
     *
     * The guide takes the customer forward; the strip is the only way back, and a tick that
     * does not take you there is a dead end — the product owner met one. The room is on the
     * screen again, in three dimensions, with its doors and windows ready to drag.
     */
    await strip.getByRole('button', { name: /Oda/ }).click()
    await expect(strip.getByRole('button', { name: /3\s*Oda/ })).toHaveAttribute('aria-current', 'step')
    await expect(page.getByRole('toolbar', { name: 'Kapı ve pencere ekle' })
      .getByRole('button', { name: 'Çift kanat pencere', exact: true })).toBeVisible()
    // Let the step's fade finish before the picture is taken.
    await page.waitForTimeout(400)
    await page.screenshot({ path: 'test-results/studio-stepper-room.png', fullPage: true })

    // --- the plan: the confirmed size is already there, and the strip says so ----------
    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}/rooms/${roomId}/plan`)
    await waitForHydration(page)

    const planStrip = page.getByRole('navigation', { name: 'Oda stüdyosu adımları' })

    await expect(page.locator('canvas')).toBeVisible({ timeout: 30_000 })
    await expect(planStrip.getByRole('link', { name: /✓\s*Oda/ })).toBeVisible()
    await expect(planStrip.getByRole('link', { name: /6\s*3B/ })).toHaveAttribute('aria-current', 'step')

    await page.screenshot({ path: 'test-results/studio-stepper-plan.png', fullPage: true })
  })
})
