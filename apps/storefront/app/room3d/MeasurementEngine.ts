import { isMeasured, rectangleOf } from './footprint'
import type { LayoutItem, RoomGeometry } from './types'

/**
 * A gap, in millimetres, between a piece and whatever is next to it.
 *
 * The endpoints are on the floor plan so the same measurement can be drawn as a line in the
 * 3D scene and as a dimension on the plan without being computed twice.
 */
export interface Measurement {
  mm: number
  from: { x: number, z: number }
  to: { x: number, z: number }
  /** What the gap is to — a wall, or another piece by name. */
  towards: string
}

/**
 * The distances that decide whether a room works.
 *
 * Not every distance: the four straight-line gaps from the selected piece to whatever it
 * would hit first going each way. That is the set somebody actually checks, and it is the set
 * that answers the only question a furniture plan is really asked — can I walk past it.
 *
 * 60 cm is a person sideways, 90 cm is a person carrying something, and both of those are
 * invisible on a screen at any zoom. Written out in centimetres they are obvious.
 */
export class MeasurementEngine {
  constructor(private geometry: RoomGeometry) {}

  setRoom(geometry: RoomGeometry): void {
    this.geometry = geometry
  }

  /**
   * The gaps around one piece.
   *
   * A gap of zero is left out rather than drawn as "0 cm": a sideboard against a wall has no
   * gap to that wall, and a label saying so is noise on top of something already obvious.
   */
  measure(item: LayoutItem, items: LayoutItem[]): Measurement[] {
    if (!isMeasured(item)) {
      return []
    }

    const rect = rectangleOf(item)

    const midX = Math.trunc((rect.x1 + rect.x2) / 2)
    const midZ = Math.trunc((rect.z1 + rect.z2) / 2)

    /*
     * Each direction starts at the wall and is pulled in by anything in the way.
     *
     * "In the way" means overlapping this piece along the other axis — a wardrobe standing
     * beside a sofa but a metre further along the room is not between the sofa and the wall,
     * and measuring to it would report a gap that does not exist in the direction shown.
     */
    let west = 0
    let east = this.geometry.width_mm
    let north = 0
    let south = this.geometry.length_mm

    let westTowards = 'duvar'
    let eastTowards = 'duvar'
    let northTowards = 'duvar'
    let southTowards = 'duvar'

    for (const other of items) {
      if (other.id === item.id || !isMeasured(other) || other.position_y_mm > 0) {
        continue
      }

      const otherRect = rectangleOf(other)

      const sharesZ = otherRect.z1 < rect.z2 && otherRect.z2 > rect.z1
      const sharesX = otherRect.x1 < rect.x2 && otherRect.x2 > rect.x1

      if (sharesZ) {
        if (otherRect.x2 <= rect.x1 && otherRect.x2 > west) {
          west = otherRect.x2
          westTowards = other.name
        }

        if (otherRect.x1 >= rect.x2 && otherRect.x1 < east) {
          east = otherRect.x1
          eastTowards = other.name
        }
      }

      if (sharesX) {
        if (otherRect.z2 <= rect.z1 && otherRect.z2 > north) {
          north = otherRect.z2
          northTowards = other.name
        }

        if (otherRect.z1 >= rect.z2 && otherRect.z1 < south) {
          south = otherRect.z1
          southTowards = other.name
        }
      }
    }

    const gaps: Measurement[] = [
      { mm: rect.x1 - west, from: { x: west, z: midZ }, to: { x: rect.x1, z: midZ }, towards: westTowards },
      { mm: east - rect.x2, from: { x: rect.x2, z: midZ }, to: { x: east, z: midZ }, towards: eastTowards },
      { mm: rect.z1 - north, from: { x: midX, z: north }, to: { x: midX, z: rect.z1 }, towards: northTowards },
      { mm: south - rect.z2, from: { x: midX, z: rect.z2 }, to: { x: midX, z: south }, towards: southTowards },
    ]

    return gaps.filter(gap => gap.mm > 0)
  }
}

/**
 * A distance as somebody would say it out loud.
 *
 * Centimetres below a metre and metres above, because "185 cm" is a number to be read and
 * "1,85 m" is a distance to be pictured, and the threshold where that flips is a metre.
 */
export function formatDistance(mm: number): string {
  if (mm < 1000) {
    return `${Math.round(mm / 10)} cm`
  }

  return `${(mm / 1000).toFixed(2).replace('.', ',')} m`
}
