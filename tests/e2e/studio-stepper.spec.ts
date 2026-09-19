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

    const strip = page.getByRole('navigation', { name: 'Oda stüdyosu' })

    /*
     * Four phases, not ten numbered circles.
     *
     * The strip used to be "1 Fotoğraf — 2 Eşyalar — 3 Oda" across to "10 Satın al", and the
     * product owner's verdict was that a guided numbered wizard is amateur and the whole thing
     * is boring. What is asserted now is what it says: where you are, and what is next.
     */
    await expect(strip).toBeVisible()
    await expect(strip.getByText('Fotoğraf', { exact: true })).toBeVisible()
    await expect(strip.getByText('sırada Eşyalar')).toBeVisible()

    // Nothing ahead is a way in: a strip that lets somebody open the design before there is
    // one hands out dead ends.
    await expect(strip.getByRole('button', { name: /Tasarım/ })).toHaveCount(0)

    // --- a photograph ---------------------------------------------------------------
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

    // The guide moves on by itself once the reading lands, and the strip moves with it.
    await expect(strip.getByText('Eşyalar', { exact: true })).toBeVisible({ timeout: 120_000 })

    /*
     * A phase behind you is a way back.
     *
     * The guide takes the customer forward; the strip is the only way back, and a part of the
     * rule that does not take you there is a dead end — the product owner met one.
     */
    await strip.getByRole('button', { name: /Fotoğraf/ }).click()
    await expect(strip.getByText('Fotoğraf', { exact: true })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Fotoğraf ekle' })).toBeVisible()

    // --- the measurements are not a phase any more ------------------------------------
    /*
     * A design is drawn on a photograph and does not need metres. They start mattering when
     * somebody puts a real sofa in a real room, so the size lives at the threshold of the 3D
     * door and has its own address rather than a box on the strip.
     */
    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}/rooms/${roomId}#oda`)

    const correct = page.getByRole('button', { name: 'Düzelt' }).first()

    if (await correct.isVisible().catch(() => false)) await correct.click()

    await fillStable(page, '#width', '420')
    await fillStable(page, '#length', '560')
    await fillStable(page, '#height', '270')
    await page.locator('#quality').selectOption('manual')
    await page.getByRole('button', { name: 'Ölçüleri kaydet' }).click()
    await expect(page.getByText('23.52 m²').first()).toBeVisible()

    await page.waitForTimeout(500)
    await page.screenshot({ path: 'test-results/studio-stepper-room.png', fullPage: true })

    // --- the plan is behind the design, not a step of its own --------------------------
    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}/rooms/${roomId}/plan`)

    const planStrip = page.getByRole('navigation', { name: 'Oda stüdyosu' })

    await expect(page.locator('canvas')).toBeVisible({ timeout: 30_000 })
    await expect(planStrip.getByText('Tasarım', { exact: true })).toBeVisible()

    await page.screenshot({ path: 'test-results/studio-stepper-plan.png', fullPage: true })
  })
})
