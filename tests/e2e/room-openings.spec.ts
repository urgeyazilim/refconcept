import { expect, test } from '@playwright/test'
import { createVerifiedAccount } from './support/accounts'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'
import { signInThrough } from './support/signin'

/**
 * Doors and windows, corrected by hand on the plan.
 *
 * The analysis reads them off a photograph; the customer slides the window to where it
 * really is. This adds a window from the form, switches to the plan, drags it along the
 * north wall with a real pointer, and reads the room back from the API to see that the wall
 * — the same rows the 3D scene, the collision rules and the render use — now has it there.
 * Then it is widened by its end, taken hold of in the 3D room and put on the left wall.
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

test.describe.configure({ timeout: 300_000 })

test.describe('room openings', () => {
  test('a window from the palette can be dragged along its wall on the plan', async ({ page, request }) => {
    const account = await createVerifiedAccount('openings-customer')
    const headers = { Authorization: `Bearer ${account.token}`, Accept: 'application/json' }

    const project = await request.post(`${API}/api/v1/projects`, { headers, data: { name: `Açıklık ${Date.now()}` } })
    const projectId = (await project.json()).data.id
    const room = await request.post(`${API}/api/v1/projects/${projectId}/rooms`, { headers, data: { name: 'Salon', room_type: 'living_room' } })
    const roomId = (await room.json()).data.id

    await signInThrough(page, STOREFRONT, account.email, /\/account$/)
    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}/rooms/${roomId}/plan`)
    await waitForHydration(page)

    await fillStable(page, 'input[type="number"] >> nth=0', '485')
    await fillStable(page, 'input[type="number"] >> nth=1', '520')
    await fillStable(page, 'input[type="number"] >> nth=2', '272')
    await page.getByRole('button', { name: 'Kaydet ve devam et' }).click()
    await expect(page.locator('canvas')).toBeVisible({ timeout: 30_000 })

    // --- add a window on the north wall, a metre from the corner ---------------------
    const section = page.locator('section', { hasText: 'Kapılar ve pencereler' })

    await expect(section.getByText('Bu odada kayıtlı kapı ya da pencere yok.')).toBeVisible()
    // From the palette at the left of the scene: a window lands centred on a free wall.
    await page.getByRole('toolbar', { name: 'Kapı ve pencere ekle' }).getByRole('button', { name: 'Çift kanat pencere', exact: true }).click()
    await expect(section.getByText(/Çift kanat pencere · kuzey duvarı · 17\d cm'de, 140 cm geniş/)).toBeVisible({ timeout: 15_000 })

    // --- the same window, three panes: one tap on the chip ----------------------------
    await section.getByRole('group', { name: 'Çift kanat pencere türü' }).getByRole('button', { name: 'Üçlü' }).click()
    await expect(section.getByText(/Üçlü pencere · kuzey duvarı · 17\d cm'de, 140 cm geniş/)).toBeVisible({ timeout: 15_000 })

    // --- drag it along the wall on the plan ------------------------------------------
    await page.getByRole('button', { name: 'Plan', exact: true }).click()

    /*
     * Where the window is on screen. A horizontal SVG line has a zero-height box, which
     * Playwright reads as "hidden", so its rectangle is asked for directly; the stroke is
     * drawn round the geometric line, so the box's centre is on the stroke.
     */
    await page.locator('svg line[stroke="transparent"]').first().waitFor({ state: 'attached' })

    const box = await page.evaluate(() => {
      const line = document.querySelector('svg line[stroke="transparent"]')!
      const rect = line.getBoundingClientRect()

      return { x: rect.x, y: rect.y, width: rect.width, height: rect.height }
    })

    const from = { x: box.x + box.width / 2, y: box.y + box.height / 2 }

    await page.mouse.move(from.x, from.y)
    await page.mouse.down()
    await page.mouse.move(from.x + 80, from.y, { steps: 10 })
    await page.mouse.move(from.x + 160, from.y, { steps: 10 })
    await page.mouse.up()

    // The list says where it went, and so does the room itself.
    await expect(section.getByText(/Üçlü pencere · kuzey duvarı · (1[89]\d|[2-9]\d\d) cm'de/)).toBeVisible({ timeout: 15_000 })

    const layout = await request.get(`${API}/api/v1/projects/${projectId}/rooms/${roomId}/layout`, { headers })
    const openings = (await layout.json()).data.openings as Array<{ id: string, wall: string, offset_mm: number, width_mm: number }>

    expect(openings).toHaveLength(1)
    expect(openings[0]!.wall).toBe('north')
    expect(openings[0]!.offset_mm).toBeGreaterThan(1_825)
    expect(openings[0]!.offset_mm).toBeLessThanOrEqual(4_850 - 1_400)

    // --- wider, by its end -----------------------------------------------------------
    const handle = await page.evaluate(() => {
      const circles = document.querySelectorAll('svg circle')
      const rect = circles[circles.length - 1]!.getBoundingClientRect()

      return { x: rect.x + rect.width / 2, y: rect.y + rect.height / 2 }
    })

    await page.mouse.move(handle.x, handle.y)
    await page.mouse.down()
    await page.mouse.move(handle.x + 60, handle.y, { steps: 8 })
    await page.mouse.up()

    await expect(section.getByText(/Üçlü pencere · kuzey duvarı · \d+ cm'de, (1[5-9]\d|[2-9]\d\d) cm geniş/)).toBeVisible({ timeout: 15_000 })

    // --- picked up in the 3D room and put on the left wall ---------------------------
    // The Plan button is a switch: pressed again it shows the 3D room.
    await page.getByRole('button', { name: 'Plan', exact: true }).click()
    await page.waitForFunction(() => (window as unknown as { __rcEditor?: unknown }).__rcEditor !== undefined)
    await page.waitForTimeout(800)

    const canvasBox = (await page.locator('canvas').boundingBox())!
    const screen = async (call: string) => {
      const point = await page.evaluate((expression) => {
        const editor = (window as unknown as { __rcEditor: Record<string, (...args: unknown[]) => unknown> }).__rcEditor
        // eslint-disable-next-line no-new-func
        return new Function('editor', `return editor.${expression}`)(editor) as { x: number, y: number } | null
      }, call)

      expect(point, `${call} must be on screen`).not.toBeNull()

      return { x: canvasBox.x + point!.x, y: canvasBox.y + point!.y }
    }

    const grab = await screen(`openingScreenPoint('${openings[0]!.id}')`)
    const west = await screen(`wallScreenPoint('west', 2600, 1700)`)

    await page.mouse.move(grab.x, grab.y)
    await page.mouse.down()
    await page.mouse.move((grab.x + west.x) / 2, (grab.y + west.y) / 2, { steps: 8 })
    await page.mouse.move(west.x, west.y, { steps: 8 })
    await page.mouse.up()

    await expect(section.getByText(/Üçlü pencere · batı duvarı · \d+ cm'de/)).toBeVisible({ timeout: 15_000 })

    // --- and gone again ------------------------------------------------------------
    await section.getByRole('button', { name: 'Kaldır' }).click()
    await expect(section.getByText('Bu odada kayıtlı kapı ya da pencere yok.')).toBeVisible({ timeout: 15_000 })
  })
})
