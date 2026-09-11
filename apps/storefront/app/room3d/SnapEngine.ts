import { footprintOf, isMeasured, rectangleOf } from './footprint'
import type { LayoutItem, RoomGeometry } from './types'

/**
 * A line the customer did not draw but was aiming at anyway.
 *
 * Drawn while a snap is active, in millimetres, so the scene can put it on the floor and the
 * plan view can draw the same line.
 */
export interface SnapGuide {
  axis: 'x' | 'z'
  /** Position of the line along its axis. */
  at: number
  /** How far the line runs, along the other axis. */
  from: number
  to: number
  kind: 'wall' | 'edge' | 'centre'
}

export interface SnapResult {
  x: number
  z: number
  guides: SnapGuide[]
}

/**
 * Pulls a dragged piece onto the lines that matter.
 *
 * Furniture in a real room is against a wall, flush with the piece beside it, or centred on
 * something. Hitting any of those with a mouse to the millimetre is impossible, and a layout
 * where the sideboard is 7 mm off the wall is a layout that looks like a mistake in every
 * render made from it afterwards — the AI is being told to reproduce a gap nobody wanted.
 *
 * So the drag is nudged, and only within a hand's width. A snap that reaches too far takes
 * the position away from somebody who meant what they did, which is worse than no snap: they
 * can see it happening and cannot stop it.
 */
export class SnapEngine {
  /**
   * How close counts as aiming at it, in millimetres.
   *
   * Roughly ten centimetres in a room, which at a normal zoom is a few pixels of pointer
   * travel. Wider than this and a piece jumps to the wall from halfway across the room.
   */
  private static readonly THRESHOLD_MM = 100

  constructor(private geometry: RoomGeometry) {}

  setRoom(geometry: RoomGeometry): void {
    this.geometry = geometry
  }

  /**
   * The position to actually use, given the one the pointer asked for.
   *
   * Each axis is decided on its own: a sofa being pushed into a corner is snapping to the
   * west wall in x and the north wall in z at the same time, and an engine that picks one
   * winner overall would leave it against one wall and near the other.
   */
  snap(item: LayoutItem, items: LayoutItem[], desired: { x: number, z: number }): SnapResult {
    if (!isMeasured(item)) {
      return { x: Math.round(desired.x), z: Math.round(desired.z), guides: [] }
    }

    const footprint = footprintOf(item)
    const halfWidth = Math.trunc(footprint.width / 2)
    const halfDepth = Math.trunc(footprint.depth / 2)

    const guides: SnapGuide[] = []

    const x = this.snapAxis('x', desired.x, halfWidth, item, items, guides)
    const z = this.snapAxis('z', desired.z, halfDepth, item, items, guides)

    return { x, z, guides }
  }

  // --- internals -------------------------------------------------------------

  private snapAxis(
    axis: 'x' | 'z',
    desired: number,
    half: number,
    item: LayoutItem,
    items: LayoutItem[],
    guides: SnapGuide[],
  ): number {
    const extent = axis === 'x' ? this.geometry.width_mm : this.geometry.length_mm

    /*
     * Every line worth catching, as the centre the piece would have if it caught it.
     *
     * Expressed as centres rather than edges so the comparison below is one subtraction. The
     * two walls and the room's middle are always there; the edges of other pieces are added
     * only when they are beside this one rather than across the room, because flushing a
     * sideboard with a wardrobe five metres away is an alignment nobody can see.
     */
    const candidates: Array<{ centre: number, kind: SnapGuide['kind'], line: number }> = [
      { centre: half, kind: 'wall', line: 0 },
      { centre: extent - half, kind: 'wall', line: extent },
      { centre: Math.trunc(extent / 2), kind: 'centre', line: Math.trunc(extent / 2) },
    ]

    for (const other of items) {
      if (other.id === item.id || !isMeasured(other)) {
        continue
      }

      const rect = rectangleOf(other)

      const low = axis === 'x' ? rect.x1 : rect.z1
      const high = axis === 'x' ? rect.x2 : rect.z2
      const middle = Math.trunc((low + high) / 2)

      // Flush on the outside of either edge, and centre to centre — the three alignments
      // people make by eye and never quite hit.
      candidates.push(
        { centre: low - half, kind: 'edge', line: low },
        { centre: high + half, kind: 'edge', line: high },
        { centre: middle, kind: 'edge', line: middle },
      )
    }

    let best: { centre: number, kind: SnapGuide['kind'], line: number } | null = null
    let bestDistance = SnapEngine.THRESHOLD_MM

    for (const candidate of candidates) {
      const distance = Math.abs(candidate.centre - desired)

      if (distance <= bestDistance) {
        best = candidate
        bestDistance = distance
      }
    }

    if (best === null) {
      return Math.round(desired)
    }

    const across = axis === 'x' ? this.geometry.length_mm : this.geometry.width_mm

    guides.push({ axis, at: best.line, from: 0, to: across, kind: best.kind })

    return best.centre
  }
}
