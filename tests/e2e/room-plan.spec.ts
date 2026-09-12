import { expect, test } from '@playwright/test'
import type { APIRequestContext } from '@playwright/test'
import { createVerifiedAccount } from './support/accounts'
import { listProduct } from './support/catalog'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'
import { signInThrough } from './support/signin'

/**
 * The 3D plan, through the real screens.
 *
 * What unit tests cannot prove and this can: that a WebGL scene actually comes up in a
 * browser, that a piece of furniture dragged into a room is still there after a reload, and
 * that the plan ends where it is meant to end — in a basket with the same products in it.
 *
 * The reload is the assertion that matters. Autosave is a timer, a request and a server that
 * replaces the whole layout; every one of those can be wrong in a way that looks perfect on
 * screen until somebody comes back the next day to a room they have to arrange again.
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

test.describe.configure({ timeout: 300_000 })

/** A project with one room, made the way the room screens make them. */
async function makeRoom(request: APIRequestContext, token: string): Promise<{ projectId: string, roomId: string }> {
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

  const project = await request.post(`${API}/api/v1/projects`, {
    headers,
    data: { name: `Plan Testi ${Date.now()}` },
  })

  expect(project.ok(), await project.text()).toBeTruthy()

  const projectId = (await project.json()).data.id

  const room = await request.post(`${API}/api/v1/projects/${projectId}/rooms`, {
    headers,
    data: { name: 'Salon', room_type: 'living_room' },
  })

  expect(room.ok(), await room.text()).toBeTruthy()

  return { projectId, roomId: (await room.json()).data.id }
}

test.describe('room plan', () => {
  test('a customer measures a room, puts a product in it and buys what is standing there', async ({ page, request }) => {
    const productName = `Plan Kanepesi ${Date.now()}`

    await listProduct(request, productName, 1_290_000, 5)

    const account = await createVerifiedAccount('plan-customer')
    const { projectId, roomId } = await makeRoom(request, account.token)

    await signInThrough(page, STOREFRONT, account.email, /\/account$/)

    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}/rooms/${roomId}/plan`)
    await waitForHydration(page)

    // --- the measurements --------------------------------------------------------
    /*
     * No photograph here, so no estimate to agree to, and the screen falls back to asking.
     * Centimetres, because that is what a tape measure says.
     */
    await expect(page.getByText('Odanın ölçüleri')).toBeVisible()

    await fillStable(page, 'input[type="number"] >> nth=0', '485')
    await fillStable(page, 'input[type="number"] >> nth=1', '520')
    await fillStable(page, 'input[type="number"] >> nth=2', '272')

    await page.getByRole('button', { name: 'Kaydet ve devam et' }).click()

    // The room is drawn at what was typed: 4.85 × 5.20 m is 25.2 m².
    await expect(page.getByText('25.2 m²')).toBeVisible({ timeout: 30_000 })
    await expect(page.locator('canvas')).toBeVisible()
    await expect(page.getByText('Odadaki ürünler (0)')).toBeVisible()

    // --- a product ----------------------------------------------------------------
    await page.getByPlaceholder('Kanepe, sehpa, kitaplık…').fill(productName)
    await page.getByRole('button', { name: 'Ara', exact: true }).click()

    const result = page.getByRole('button', { name: new RegExp(productName) })

    await expect(result).toBeVisible({ timeout: 30_000 })
    await result.click()

    await expect(page.getByText('Odadaki ürünler (1)')).toBeVisible()

    // --- and it is still there tomorrow ---------------------------------------------
    /*
     * The point of the reload. Autosave is a timer, a request, and a server that replaces
     * the whole layout — each of which can be wrong in a way that looks perfect on screen
     * until somebody comes back to a room they have to arrange all over again.
     */
    await page.waitForTimeout(3_000)
    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}/rooms/${roomId}/plan`)
    await waitForHydration(page)

    await expect(page.getByText('Odadaki ürünler (1)')).toBeVisible({ timeout: 30_000 })

    // --- into the basket -------------------------------------------------------------
    await page.getByRole('button', { name: 'Odadakileri sepete ekle' }).click()

    await expect(page.getByText('1 ürün sepete eklendi.')).toBeVisible({ timeout: 30_000 })

    await gotoInteractive(page, `${STOREFRONT}/cart`)
    await waitForHydration(page)

    // The same product, by name. A plan that ends in a basket containing something else is
    // a plan that was decoration.
    await expect(page.getByText(productName).first()).toBeVisible({ timeout: 30_000 })
  })
})
