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
 * and lights "Düzenle". The strip is the one part of the studio that is on every screen,
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
    await expect(strip.getByText('Sıradaki:')).toBeVisible()
    await expect(strip.locator('span').filter({ hasText: /^Tanıma$/ })).toBeVisible()

    // --- a photograph, then the size confirmed on the Onay step ----------------------
    await page.locator('input[type="file"]').setInputFiles({
      name: 'salon.png',
      mimeType: 'image/png',
      buffer: pngBuffer(1024, 768),
    })
    await expect(page.getByText('Tasarım bu fotoğraftan')).toBeVisible()

    // The size lives on the Onay step; the strip opens it without waiting for the reading.
    await strip.getByRole('button', { name: /Onay/ }).click()
    await fillStable(page, '#width', '420')
    await fillStable(page, '#length', '560')
    await fillStable(page, '#height', '270')
    await page.locator('#quality').selectOption('manual')
    await page.getByRole('button', { name: 'Ölçüleri kaydet' }).click()
    await expect(page.getByText('23.52 m²').first()).toBeVisible()

    // Fotoğraf and Onay are ticked; the guide has moved on to what it asks next.
    await expect(strip.getByRole('button', { name: /✓\s*Fotoğraf/ })).toBeVisible()
    await expect(strip.getByRole('button', { name: /✓\s*Onay/ })).toBeVisible()

    // Back on the Onay step the doors and windows are drawn from above, ready to drag.
    await strip.getByRole('button', { name: /Onay/ }).click()
    await expect(page.getByRole('button', { name: '+ Pencere' })).toBeVisible()
    // Let the step's fade finish before the picture is taken.
    await page.waitForTimeout(400)
    await page.screenshot({ path: 'test-results/studio-stepper-room.png', fullPage: true })

    // --- the plan: the confirmed size is already there, and the strip says so ----------
    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}/rooms/${roomId}/plan`)
    await waitForHydration(page)

    const planStrip = page.getByRole('navigation', { name: 'Oda stüdyosu adımları' })

    await expect(page.locator('canvas')).toBeVisible({ timeout: 30_000 })
    await expect(planStrip.getByRole('link', { name: /✓\s*Onay/ })).toBeVisible()
    await expect(planStrip.getByRole('link', { name: /6\s*Düzenle/ })).toHaveAttribute('aria-current', 'step')

    await page.screenshot({ path: 'test-results/studio-stepper-plan.png', fullPage: true })
  })
})
