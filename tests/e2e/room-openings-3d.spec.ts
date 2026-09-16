import { expect, test } from '@playwright/test'
import { gotoInteractive } from './support/hydration'

/**
 * A window picked up in the 3D room and put down on a wall — the same wall, then another.
 *
 * The lab room is the storyboard's, with a window on the north wall. It is taken hold of
 * where it is on screen and let go further along the wall; the editor's own openings say
 * where it went. Then it is dragged towards the west wall and lands on it, because the
 * customer is not asked to name walls.
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'

test.describe.configure({ timeout: 180_000 })

interface Point { x: number, y: number }

test.describe('openings in the 3D room', () => {
  test('a window is dragged along its wall and onto another', async ({ page }) => {
    await gotoInteractive(page, `${STOREFRONT}/lab/room3d`)
    await page.waitForFunction(() => (window as unknown as { __rcEditor?: unknown }).__rcEditor !== undefined)

    const canvas = page.locator('canvas')
    await canvas.scrollIntoViewIfNeeded()
    // Let the camera settle and the first frame draw.
    await page.waitForTimeout(600)

    const box = (await canvas.boundingBox())!

    const screen = async (call: string): Promise<Point> => {
      const point = await page.evaluate((expression) => {
        const editor = (window as unknown as { __rcEditor: Record<string, (...args: unknown[]) => unknown> }).__rcEditor
        // eslint-disable-next-line no-new-func
        return new Function('editor', `return editor.${expression}`)(editor) as Point | null
      }, call)

      expect(point, `${call} must be on screen`).not.toBeNull()

      return { x: box.x + point!.x, y: box.y + point!.y }
    }

    const opening = async (id: string) => page.evaluate((wanted) => {
      const editor = (window as unknown as { __rcEditor: { openingsNow: () => Array<{ id: string, wall: string, offset_mm: number }> } }).__rcEditor

      return editor.openingsNow().find(entry => entry.id === wanted) ?? null
    }, id)

    // --- along the north wall ---------------------------------------------------------
    const from = await screen(`openingScreenPoint('w1')`)
    // The window is 1800 wide; its centre at 2500 + 900 puts its left edge at 2500.
    const to = await screen(`wallScreenPoint('north', 3400, 1700)`)

    await page.mouse.move(from.x, from.y)
    await page.mouse.down()
    await page.mouse.move((from.x + to.x) / 2, (from.y + to.y) / 2, { steps: 8 })
    await page.mouse.move(to.x, to.y, { steps: 8 })
    await page.mouse.up()

    const moved = await opening('w1')

    expect(moved?.wall).toBe('north')
    expect(moved!.offset_mm).toBeGreaterThan(2_000)
    expect(moved!.offset_mm).toBeLessThan(3_050)

    // --- and onto the west wall ---------------------------------------------------------
    const again = await screen(`openingScreenPoint('w1')`)
    const west = await screen(`wallScreenPoint('west', 2600, 1700)`)

    await page.mouse.move(again.x, again.y)
    await page.mouse.down()
    await page.mouse.move((again.x + west.x) / 2, (again.y + west.y) / 2, { steps: 8 })
    await page.mouse.move(west.x, west.y, { steps: 8 })
    await page.mouse.up()

    const crossed = await opening('w1')

    expect(crossed?.wall).toBe('west')
    expect(crossed!.offset_mm).toBeGreaterThan(1_200)
    expect(crossed!.offset_mm).toBeLessThan(3_400)
  })
})
