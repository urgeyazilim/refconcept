import { describe, expect, it } from 'vitest'

import { CollisionEngine } from './CollisionEngine'
import type { LayoutItem, RoomGeometry, RoomOpening } from './types'

/**
 * The same cases as `LayoutGeometryTest.php`, in the same order, with the same numbers.
 *
 * That is the entire point of this file. There are two copies of the collision rules — one
 * here so a warning arrives in the frame the sofa touches the sideboard, one on the server
 * because nothing arriving over HTTP can be trusted — and two copies of a rule drift apart
 * the first week nobody is looking. When they do, the customer is told a position is fine,
 * drags twenty minutes of work into place and is refused on save, or worse is not refused
 * and the room does not work when the delivery arrives.
 *
 * The room is the storyboard's: 4.85 m across, 5.20 m deep.
 */
const room: RoomGeometry = { id: 'r', width_mm: 4_850, length_mm: 5_200, height_mm: 2_720 }

function place(
  id: string,
  category: string,
  widthMm: number,
  depthMm: number,
  x: number,
  z: number,
  rotation = 0,
  y = 0,
): LayoutItem {
  return {
    id,
    product_id: id,
    sku_id: id,
    name: id,
    category,
    position_x_mm: x,
    position_y_mm: y,
    position_z_mm: z,
    rotation_y_deg: rotation,
    locked: false,
    collision_state: 'ok',
    width_mm: widthMm,
    height_mm: 800,
    depth_mm: depthMm,
    image_url: null,
    model_url: null,
  }
}

const engine = (openings: RoomOpening[] = []) => new CollisionEngine(room, openings)

function opening(type: string, wall: RoomOpening['wall'], offset: number, width: number): RoomOpening {
  return { id: `${type}-${wall}`, type, wall, offset_mm: offset, width_mm: width, height_mm: 2_100, sill_height_mm: 0 }
}

describe('collisionEngine', () => {
  it('accepts a sofa standing on its own in the middle of the room', () => {
    const sofa = place('sofa', 'kanepe', 2_200, 900, 2_400, 2_600)

    expect(engine().evaluate([sofa]).get('sofa')).toBe('ok')
  })

  it('refuses two pieces standing in the same place', () => {
    const sofa = place('sofa', 'kanepe', 2_200, 900, 2_400, 2_600)
    const sideboard = place('sideboard', 'konsol', 1_400, 400, 2_500, 2_650)

    const states = engine().evaluate([sofa, sideboard])

    // Not a warning: a sideboard cannot be delivered into the space a sofa occupies.
    expect(states.get('sofa')).toBe('blocked')
    expect(states.get('sideboard')).toBe('blocked')
  })

  it('lets a coffee table stand on a rug', () => {
    const rug = place('rug', 'hali', 2_400, 1_700, 2_400, 2_600)
    const table = place('table', 'sehpa', 900, 900, 2_400, 2_600)

    const states = engine().evaluate([rug, table])

    // Without this exemption every layout containing a carpet reports four collisions, and
    // somebody who sees four warnings they know are wrong stops reading the fifth.
    expect(states.get('rug')).toBe('ok')
    expect(states.get('table')).toBe('ok')
  })

  it('refuses a piece that sticks out through a wall', () => {
    const sofa = place('sofa', 'kanepe', 2_200, 900, 300, 2_600)

    expect(engine().evaluate([sofa]).get('sofa')).toBe('blocked')
  })

  it('refuses a wardrobe standing in the doorway', () => {
    const wardrobe = place('wardrobe', 'gardirop', 1_200, 600, 4_500, 850, 90)

    const states = engine([opening('door', 'east', 400, 900)]).evaluate([wardrobe])

    expect(states.get('wardrobe')).toBe('blocked')
  })

  it('warns rather than refuses when something stands in front of a window', () => {
    const sofa = place('sofa', 'kanepe', 2_200, 900, 1_600, 500)

    const states = engine([opening('window', 'north', 720, 1_800)]).evaluate([sofa])

    // A sofa with its back to a window is an ordinary arrangement somebody may want and be
    // told about. A wardrobe across the only door is not a taste question.
    expect(states.get('sofa')).toBe('warning')
  })

  it('treats a balcony door like a door', () => {
    const sideboard = place('sideboard', 'konsol', 1_400, 450, 1_800, 4_900)

    const states = engine([opening('balcony_door', 'south', 1_000, 1_600)]).evaluate([sideboard])

    expect(states.get('sideboard')).toBe('blocked')
  })

  it('lets a picture hang above a sideboard', () => {
    const sideboard = place('sideboard', 'konsol', 1_400, 450, 2_400, 300)
    const picture = place('picture', 'tablo', 900, 50, 2_400, 250, 0, 1_500)

    const states = engine().evaluate([sideboard, picture])

    // They share a floor plan and not a cubic centimetre of space.
    expect(states.get('sideboard')).toBe('ok')
    expect(states.get('picture')).toBe('ok')
  })

  it('lets a turned sofa and a table share the box round them but not the floor', () => {
    /*
     * A sofa on the diagonal and a table tucked into the corner its bounding box covers. The
     * box says they collide; the outlines say they do not, and the outlines are right — this
     * is the arrangement people make on purpose. Mirrored on the server with the same numbers.
     */
    const sofa = place('sofa', 'kanepe', 2_200, 900, 2_400, 2_600, 45)
    const clear = place('clear', 'sehpa', 500, 500, 3_300, 1_700)
    const across = place('across', 'sehpa', 500, 500, 2_400, 3_200)

    expect(engine().evaluate([sofa, clear]).get('clear')).toBe('ok')
    expect(engine().evaluate([sofa, across]).get('across')).toBe('blocked')
  })

  it('lets a curtain hang behind the sofa and a picture over the door', () => {
    // On the wall is not on the floor. Mirrored on the server with the same numbers.
    const sofa = place('sofa', 'kanepe', 2_200, 900, 2_400, 500)
    const curtain = place('curtain', 'perde', 2_000, 20, 2_400, 10)
    const picture = place('picture', 'tablo', 600, 30, 4_250, 15, 0, 1_500)

    const states = engine([opening('door', 'north', 3_800, 900)]).evaluate([sofa, curtain, picture])

    expect(states.get('curtain')).toBe('ok')
    expect(states.get('sofa')).toBe('ok')
    expect(states.get('picture')).toBe('ok')
  })

  it('leaves an unmeasured piece alone rather than guessing its size', () => {
    const sofa = place('sofa', 'kanepe', 2_200, 900, 2_400, 2_600)
    const unmeasured = { ...place('lamp', 'aydinlatma', 0, 0, 2_400, 2_600), width_mm: null, depth_mm: null }

    const states = engine().evaluate([sofa, unmeasured])

    // It is drawn as a placeholder so the missing dimensions are visible. Refusing a position
    // on the strength of a placeholder's size would be refusing it on the strength of nothing.
    expect(states.get('lamp')).toBe('ok')
    expect(states.get('sofa')).toBe('ok')
  })

  it('answers for a position before anything has moved there', () => {
    const sofa = place('sofa', 'kanepe', 2_200, 900, 2_400, 2_600)
    const sideboard = place('sideboard', 'konsol', 1_400, 400, 800, 400)

    // The drag's question, asked sixty times a second: what would happen if I let go here.
    expect(engine().stateAt(sideboard, [sofa, sideboard], { x: 2_400, z: 2_600 })).toBe('blocked')
    expect(sideboard.position_x_mm).toBe(800)
  })
})
