import { describe, expect, it } from 'vitest'

import { ConstraintEngine } from './ConstraintEngine'
import { polygonOf, polygonsOverlap } from './footprint'
import type { LayoutItem, RoomGeometry, RoomOpening } from './types'

/**
 * Furniture stays inside the room and out of each other — as a rule, not a warning.
 *
 * The first editor flagged a bad position and left the piece there, and the product owner's
 * screenshot showed exactly what that looks like. Everything here is the storyboard's own
 * sentence, "ürünler duvarların içine giremez", made testable.
 */

/** 4.85 × 5.20 m, the storyboard's own room. */
const room: RoomGeometry = { id: 'room', width_mm: 4850, length_mm: 5200, height_mm: 2720 }

function piece(
  id: string,
  width: number,
  depth: number,
  x: number,
  z: number,
  extra: Partial<LayoutItem> = {},
): LayoutItem {
  return {
    id,
    product_id: `product-${id}`,
    sku_id: `sku-${id}`,
    name: id,
    category: 'kanepe',
    position_x_mm: x,
    position_y_mm: 0,
    position_z_mm: z,
    rotation_y_deg: 0,
    locked: false,
    collision_state: 'ok',
    width_mm: width,
    height_mm: 800,
    depth_mm: depth,
    image_url: null,
    model_url: null,
    ...extra,
  }
}

const door: RoomOpening = {
  id: 'door',
  type: 'door',
  wall: 'north',
  offset_mm: 500,
  width_mm: 900,
  height_mm: 2100,
  sill_height_mm: null,
} as RoomOpening

const engine = (openings: RoomOpening[] = []) => new ConstraintEngine(room, openings)

describe('constraintEngine', () => {
  it('stops a piece at the wall instead of letting it through', () => {
    const sofa = piece('sofa', 2200, 900, 2400, 2600)

    // Dragged far past the west wall. It stops with its edge on the wall.
    const held = engine().settle(sofa, [sofa], { x: -800, z: 2600 })

    expect(held).toEqual({ x: 1100, z: 2600, settled: true })
  })

  it('clamps by the footprint the piece has after turning', () => {
    const sofa = piece('sofa', 2200, 900, 2400, 2600)

    // Facing east it is 900 wide across the room, so it may stand much nearer the east wall
    // than its untumed width would allow.
    const held = engine().settle(sofa, [sofa], { x: 4850, z: 2600, rotation: 90 })

    expect(held).toEqual({ x: 4850 - 450, z: 2600, settled: true })
  })

  it('slides a piece off another by the shortest way out', () => {
    const sofa = piece('sofa', 2200, 900, 2400, 2600)
    const table = piece('table', 900, 500, 2400, 3500, { category: 'sehpa' })

    // The table dragged so it overlaps the sofa's front edge by 200 mm. The short way out is
    // back the way it came, to edge to edge; not sideways past the whole sofa.
    const held = engine().settle(table, [sofa, table], { x: 2400, z: 3100 })

    expect(held).toEqual({ x: 2400, z: 3300, settled: true })
  })

  it('goes back where it was when there is nowhere else', () => {
    // A sofa the full width of the room, and a second one that cannot fit beside it
    // anywhere it is dropped along the same band.
    const first = piece('first', 4850, 5200, 2425, 2600)
    const second = piece('second', 1000, 1000, 600, 600)

    const held = engine().settle(second, [first, second], { x: 2400, z: 2600 }, { x: 600, z: 600 })

    expect(held).toEqual({ x: 600, z: 600, settled: false })
  })

  it('lets a rug go under the sofa but not through the wall', () => {
    const sofa = piece('sofa', 2200, 900, 2400, 2600)
    const rug = piece('rug', 2000, 3000, 2400, 2600, { category: 'hali' })

    // Onto the sofa: fine, that is what a rug is for. Past the wall: still no.
    expect(engine().settle(rug, [sofa, rug], { x: 2400, z: 2600 })).toEqual({ x: 2400, z: 2600, settled: true })
    expect(engine().settle(rug, [sofa, rug], { x: -500, z: 2600 })).toEqual({ x: 1000, z: 2600, settled: true })
  })

  it('keeps a raised piece over the furniture and inside the room', () => {
    // A pendant light, hung from the ceiling over the sofa: off the floor, so over the sofa
    // is fine, but never outside the walls.
    const sofa = piece('sofa', 2200, 900, 2400, 2600)
    const pendant = piece('pendant', 800, 800, 2400, 2600, { category: 'tavan-aydinlatma', position_y_mm: 2000 })

    expect(engine().settle(pendant, [sofa, pendant], { x: 2400, z: 2600 })).toEqual({ x: 2400, z: 2600, settled: true })
    expect(engine().settle(pendant, [sofa, pendant], { x: 9000, z: 2600 })).toEqual({ x: 4450, z: 2600, settled: true })
  })

  it('keeps the floor in front of a door clear', () => {
    const chest = piece('chest', 600, 400, 950, 300, { category: 'komodin' })

    /*
     * Dropped in the door's swing — 900 mm deep from the north wall — it is pushed out of it.
     * The shortest way out is back through the wall, which is no way out; of the two that
     * stay in the room, sideways (750 mm) beats deeper into the room (800 mm).
     */
    const held = engine([door]).settle(chest, [chest], { x: 950, z: 300 })

    expect(held).toEqual({ x: 1400 + 300, z: 300, settled: true })
  })

  it('pushes a turned piece out along its own edges', () => {
    const sofa = piece('sofa', 2200, 900, 2400, 2600, { rotation_y_deg: 45 })
    const table = piece('table', 500, 500, 2400, 3200, { category: 'sehpa' })

    /*
     * Dropped across the diagonal sofa's front edge; the short way out is perpendicular to
     * that edge, not along the room's axes. At 45° the front faces -x/+z, so that is where
     * the table goes — and afterwards the two outlines no longer share floor.
     */
    const held = engine().settle(table, [sofa, table], { x: 2400, z: 3200 })

    expect(held.settled).toBe(true)
    expect(held.x).toBeLessThan(2400)
    expect(held.z).toBeGreaterThan(3200)
    expect(polygonsOverlap(polygonOf(table, held), polygonOf(sofa))).toBe(false)
  })

  it('hangs a picture on the nearest wall and slides it along it', () => {
    const sofa = piece('sofa', 2200, 900, 2400, 2600)
    const picture = piece('picture', 800, 30, 0, 0, { category: 'tablo', position_y_mm: 1200 })

    // Dropped near the west wall: flush to it, facing east, level with the pointer.
    const west = engine().settle(picture, [sofa, picture], { x: 300, z: 2000 })

    expect(west).toEqual({ x: 15, z: 2000, rotation: 270, settled: true })

    // Dragged to the far end of that wall: it stops at the corner rather than leaving it.
    const corner = engine().settle(picture, [sofa, picture], { x: 100, z: 5000 })

    expect(corner).toEqual({ x: 15, z: 5200 - 400, rotation: 270, settled: true })

    // Over the sofa, nearer the south wall: it goes on the south wall, not into the sofa.
    const south = engine().settle(picture, [sofa, picture], { x: 2400, z: 4900 })

    expect(south).toEqual({ x: 2400, z: 5200 - 15, rotation: 180, settled: true })
  })

  it('leaves an unmeasured piece exactly where it was put', () => {
    const mystery = piece('mystery', 0, 0, 100, 100, { width_mm: null, depth_mm: null })

    expect(engine().settle(mystery, [mystery], { x: -400, z: 9000 })).toEqual({ x: -400, z: 9000, settled: true })
  })
})
