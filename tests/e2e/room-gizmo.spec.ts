import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { createVerifiedAccount } from './support/accounts'
import { listProduct } from './support/catalog'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'
import { signInThrough } from './support/signin'

/**
 * The handles on a piece, driven with a real pointer.
 *
 * What the unit tests cannot prove and this can: that the ring turns a piece by the pointer
 * and lands on a fifteen-degree step, and that the arrow pushed at a wall stops the piece at
 * the wall — with the handle still in the customer's hand. The storyboard's own sentence,
 * "ürünler duvarların içine giremez", against a browser rather than a function.
 *
 * The handles are found by asking the scene where they are: the editor projects the selected
 * piece's centre to the screen, and the handles are drawn around it. Their exact pixels
 * depend on the camera, so the arrow is located by hovering a small grid and reading back
 * which axis the gizmo reports under the pointer.
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

const ROOM = { width: 480, length: 420, height: 270 }

test.describe.configure({ timeout: 300_000 })

interface Placement {
  position_x_mm: number
  position_z_mm: number
  rotation_y_deg: number
  width_mm: number
  depth_mm: number
  screen: { x: number, y: number } | null
}

/** The selected piece as the editor holds it, plus where its centre is on the canvas. */
async function placement(page: Page): Promise<Placement> {
  return page.evaluate(() => {
    const editor = (window as unknown as { __rcEditor: Record<string, unknown> }).__rcEditor
    const id = editor.selectedId as string
    const item = (editor.items as Array<Placement & { id: string }>).find(piece => piece.id === id)!
    const scene = editor.scene as { projectToScreen: (point: { x: number, z: number }) => { x: number, y: number } | null }

    return {
      position_x_mm: item.position_x_mm,
      position_z_mm: item.position_z_mm,
      rotation_y_deg: item.rotation_y_deg,
      width_mm: item.width_mm,
      depth_mm: item.depth_mm,
      screen: scene.projectToScreen({ x: item.position_x_mm, z: item.position_z_mm }),
    }
  })
}

async function hoveredAxis(page: Page): Promise<string | null> {
  return page.evaluate(() => (window as unknown as { __rcEditor: { gizmo: { controls: { axis: string | null } } } }).__rcEditor.gizmo.controls.axis)
}

/** The canvas's box on screen, after bringing it into view — a button click may have scrolled it away. */
async function canvasBox(page: Page): Promise<{ x: number, y: number }> {
  await page.locator('canvas').scrollIntoViewIfNeeded()
  await page.waitForTimeout(300)

  const box = await page.locator('canvas').boundingBox()

  expect(box).not.toBeNull()

  return { x: box!.x, y: box!.y }
}

test.describe('room gizmo', () => {
  test('the ring turns a piece in steps and the arrow stops it at the wall', async ({ page, request }) => {
    const productName = `Gizmo Pufu ${Date.now()}`

    await listProduct(request, productName, 390_000, 5)

    const account = await createVerifiedAccount('gizmo-customer')
    const headers = { Authorization: `Bearer ${account.token}`, Accept: 'application/json' }

    const project = await request.post(`${API}/api/v1/projects`, { headers, data: { name: `Gizmo ${Date.now()}` } })
    const projectId = (await project.json()).data.id
    const room = await request.post(`${API}/api/v1/projects/${projectId}/rooms`, { headers, data: { name: 'Salon', room_type: 'living_room' } })
    const roomId = (await room.json()).data.id

    await signInThrough(page, STOREFRONT, account.email, /\/account$/)
    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}/rooms/${roomId}/plan`)
    await waitForHydration(page)

    await fillStable(page, 'input[type="number"] >> nth=0', String(ROOM.width))
    await fillStable(page, 'input[type="number"] >> nth=1', String(ROOM.length))
    await fillStable(page, 'input[type="number"] >> nth=2', String(ROOM.height))
    await page.getByRole('button', { name: 'Kaydet ve devam et' }).click()
    await expect(page.locator('canvas')).toBeVisible({ timeout: 30_000 })

    // The editor's dev hook, set when the scene mounts. Without it there is nothing to ask.
    await page.waitForFunction(() => Boolean((window as unknown as { __rcEditor?: unknown }).__rcEditor), null, { timeout: 30_000 })

    await page.getByPlaceholder('Kanepe, sehpa, kitaplık…').fill(productName)
    await page.getByRole('button', { name: 'Ara', exact: true }).click()
    const result = page.getByRole('button', { name: new RegExp(productName) })
    await expect(result).toBeVisible({ timeout: 30_000 })
    await result.click()

    // Added pieces are selected, so the handles are already on it.
    await expect(page.getByText('Odadaki ürünler (1)')).toBeVisible()
    await page.waitForTimeout(1_500)

    // --- the ring --------------------------------------------------------------
    await page.getByRole('button', { name: 'Döndür', exact: true }).click()

    const before = await placement(page)
    const box = await canvasBox(page)

    expect(before.screen).not.toBeNull()

    // The rim, to the right of the piece, dragged downwards and round.
    const rim = { x: box.x + before.screen!.x + 62, y: box.y + before.screen!.y }

    await page.mouse.move(rim.x, rim.y)
    await page.waitForTimeout(300)
    await page.mouse.down()
    await page.mouse.move(rim.x - 15, rim.y + 40, { steps: 15 })
    await page.mouse.move(rim.x - 40, rim.y + 55, { steps: 15 })
    await page.mouse.up()
    await page.waitForTimeout(1_200)

    const turned = await placement(page)

    expect(turned.rotation_y_deg).not.toBe(before.rotation_y_deg)
    // Fifteen-degree steps: furniture in a room is square to something almost always.
    expect(turned.rotation_y_deg % 15).toBe(0)

    // --- the arrow, into the wall ---------------------------------------------------
    await page.getByRole('button', { name: 'Taşı', exact: true }).click()

    const box2 = await canvasBox(page)
    const centre = { x: box2.x + turned.screen!.x, y: box2.y + turned.screen!.y }

    // Find the +x arrow by hovering round the centre until the gizmo says X.
    let arrow: { x: number, y: number } | null = null

    for (let dy = -40; dy <= 40 && arrow === null; dy += 10) {
      for (let dx = 10; dx <= 80; dx += 10) {
        await page.mouse.move(centre.x + dx, centre.y + dy)
        await page.waitForTimeout(40)

        if ((await hoveredAxis(page)) === 'X') {
          arrow = { x: centre.x + dx, y: centre.y + dy }
          break
        }
      }
    }

    expect(arrow, 'the +x arrow is somewhere to the right of the piece').not.toBeNull()

    await page.mouse.move(arrow!.x, arrow!.y)
    await page.waitForTimeout(200)
    await page.mouse.down()
    // Far past the east wall.
    await page.mouse.move(arrow!.x + 700, arrow!.y + 220, { steps: 30 })
    await page.mouse.up()
    await page.waitForTimeout(1_200)

    const moved = await placement(page)

    /*
     * Stopped at the wall, with its turned footprint entirely inside the room. The half-width
     * is that of the box the turned piece fits in, which is what the clamp uses — and the
     * piece is well past where it started, so the arrow did take it.
     */
    const radians = (turned.rotation_y_deg * Math.PI) / 180
    const halfWidth = Math.trunc(Math.round(moved.width_mm * Math.abs(Math.cos(radians)) + moved.depth_mm * Math.abs(Math.sin(radians))) / 2)

    expect(moved.position_x_mm).toBeGreaterThan(turned.position_x_mm + 500)
    expect(moved.position_x_mm).toBeLessThanOrEqual(ROOM.width * 10 - halfWidth)
    expect(moved.rotation_y_deg).toBe(turned.rotation_y_deg)
  })
})
