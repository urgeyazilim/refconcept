import { describe, expect, it } from 'vitest'

import { MeasurementEngine, formatDistance } from './MeasurementEngine'
import { SnapEngine } from './SnapEngine'
import { againstWall } from './footprint'
import type { LayoutItem, RoomGeometry } from './types'

const room: RoomGeometry = { id: 'r', width_mm: 4_850, length_mm: 5_200, height_mm: 2_720 }

function place(id: string, widthMm: number, depthMm: number, x: number, z: number, rotation = 0): LayoutItem {
  return {
    id,
    product_id: id,
    sku_id: id,
    name: id,
    category: 'kanepe',
    position_x_mm: x,
    position_y_mm: 0,
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

describe('snapEngine', () => {
  const snaps = new SnapEngine(room)

  it('puts a piece flat against the wall it was aimed at', () => {
    const sofa = place('sofa', 2_200, 900, 2_400, 2_600)

    // Dropped with its back 40 mm off the north wall — a gap nobody meant and every render
    // made from the layout afterwards would faithfully reproduce.
    const result = snaps.snap(sofa, [], { x: 2_400, z: 490 })

    expect(result.z).toBe(450)
    expect(result.guides).toContainEqual({ axis: 'z', at: 0, from: 0, to: 4_850, kind: 'wall' })
  })

  it('leaves a position alone when it was not aimed at anything', () => {
    const sofa = place('sofa', 2_200, 900, 2_400, 2_600)

    // Nowhere near a wall, another piece, or the middle of the room — 2425 × 2600 is the
    // centre and does snap, which is what the guide is for.
    const result = snaps.snap(sofa, [], { x: 1_500, z: 1_800 })

    // A snap that reaches across the room takes the position away from somebody who meant
    // what they did, which is worse than no snap: they can see it happening and cannot stop it.
    expect(result).toEqual({ x: 1_500, z: 1_800, guides: [] })
  })

  it('lines a piece up flush with the one beside it', () => {
    const sofa = place('sofa', 2_200, 900, 2_400, 2_600)
    const table = place('table', 900, 900, 4_000, 2_640)

    // Its left edge is 50 mm from the sofa's right edge: aiming at flush and missing.
    const result = snaps.snap(table, [sofa], { x: 4_000, z: 2_640 })

    expect(result.x).toBe(3_950)
    expect(result.z).toBe(2_600)
  })

  it('does not move a piece the catalogue never measured', () => {
    const unmeasured = { ...place('lamp', 0, 0, 1_000, 1_000), width_mm: null, depth_mm: null }

    const result = snaps.snap(unmeasured, [], { x: 1_040, z: 1_040 })

    // Its edges are not known, so there is nothing to put flush against anything.
    expect(result).toEqual({ x: 1_040, z: 1_040, guides: [] })
  })
})

describe('measurementEngine', () => {
  const measurements = new MeasurementEngine(room)

  it('measures to the walls when nothing is in the way', () => {
    const sofa = place('sofa', 2_200, 900, 2_400, 2_600)

    const gaps = measurements.measure(sofa, [])

    expect(gaps).toHaveLength(4)
    expect(gaps.map(gap => gap.mm)).toEqual([1_300, 1_350, 2_150, 2_150])
    expect(gaps.every(gap => gap.towards === 'duvar')).toBe(true)
  })

  it('measures to the nearest piece rather than past it', () => {
    const sofa = place('sofa', 2_200, 900, 2_400, 2_600)
    const table = place('table', 900, 900, 2_400, 4_000)

    const gaps = measurements.measure(sofa, [table])

    // 4000 - 450 = 3550 is the table's near edge; the sofa's back is at 3050.
    const south = gaps.find(gap => gap.towards === 'table')

    expect(south?.mm).toBe(500)
  })

  it('ignores a piece that is beside rather than in front', () => {
    const sofa = place('sofa', 2_200, 900, 2_400, 2_600)

    // Well clear along z: it is not between the sofa and the south wall, and measuring to it
    // would report a gap that does not exist in the direction shown.
    const wardrobe = place('wardrobe', 600, 600, 4_400, 600)

    expect(measurements.measure(sofa, [wardrobe]).every(gap => gap.towards === 'duvar')).toBe(true)
  })

  it('leaves out a gap of nothing', () => {
    const sofa = place('sofa', 2_200, 900, 2_400, 450)

    // Against the north wall. "0 cm" is noise on top of something already obvious.
    expect(measurements.measure(sofa, []).some(gap => gap.mm === 0)).toBe(false)
  })
})

describe('formatDistance', () => {
  it('says centimetres below a metre and metres above', () => {
    // "185 cm" is a number to be read; "1,85 m" is a distance to be pictured.
    expect(formatDistance(850)).toBe('85 cm')
    expect(formatDistance(1_850)).toBe('1,85 m')
  })
})

describe('againstWall', () => {
  const room: RoomGeometry = { id: 'r', width_mm: 4_850, length_mm: 5_200, height_mm: 2_720 }

  it('turns a piece to face the room and stands it off the wall', () => {
    const sofa = place('sofa', 2_200, 900, 2_400, 2_600)

    const north = againstWall(sofa, 'north', room, 60)

    // Facing south, which is what "against the north wall" means from inside the room.
    expect(north.rotation_y_deg).toBe(0)
    expect(north.position_z_mm).toBe(510)
    expect(north.position_x_mm).toBe(2_400)
  })

  it('measures with the footprint the piece has after being turned', () => {
    const sofa = place('sofa', 2_200, 900, 2_400, 2_600)

    const east = againstWall(sofa, 'east', room, 60)

    /*
     * The subtlety this test exists for. A 2200 × 900 sofa against the east wall stands 900
     * deep across the room, so its centre is 450 mm plus the gap from the wall. Offsetting it
     * by the width it had before the turn would put half of it through the wall — a position
     * the collision rules then refuse, on an alignment the customer asked for by name.
     */
    // Turned to face west — into the room from the east wall — which is 90 in the scene's
    // clockwise-from-south convention.
    expect(east.rotation_y_deg).toBe(90)
    expect(east.position_x_mm).toBe(4_850 - 450 - 60)
  })

  it('leaves the other axis where the customer put it', () => {
    const sofa = place('sofa', 2_200, 900, 1_200, 3_400)

    // Aligning is a tidy-up, not a decision: along the wall, they have already chosen.
    expect(againstWall(sofa, 'south', room, 60).position_x_mm).toBe(1_200)
  })
})
