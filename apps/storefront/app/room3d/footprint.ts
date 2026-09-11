import type { LayoutItem, RoomGeometry, RoomOpening } from './types'

/**
 * The floor-plan arithmetic, shared by everything that needs to know where a piece stands.
 *
 * This is deliberately a copy of `App\Domains\Projects\Services\LayoutGeometry` — same
 * constants, same rectangles, same rounding. The duplication is the point: the browser's
 * answer has to arrive while somebody is still dragging, and a round trip per pointer move is
 * a warning nobody waits for. The server's answer is the one that decides.
 *
 * Because there are two copies, they have to agree, and the places they could quietly drift
 * apart are named below. Whenever one side changes, the other is not optional.
 */

/** A rectangle on the floor plan, in millimetres from the room's origin corner. */
export interface Rect {
  x1: number
  z1: number
  x2: number
  z2: number
}

/**
 * How much two pieces may overlap before it is a collision rather than a snap.
 *
 * Snapping puts furniture edge to edge on purpose, and edge to edge is an overlap of zero
 * that floating point turns into a fraction of a millimetre either way.
 */
export const TOUCH_TOLERANCE_MM = 20

/** Clearance a door needs in front of it. A door that opens into a wardrobe is not a door. */
export const DOOR_CLEARANCE_MM = 900

/** Clearance in front of a window. Smaller: a sofa with its back to one is ordinary. */
export const WINDOW_CLEARANCE_MM = 300

/** The categories other things are meant to stand on top of. */
const UNDERFOOT_CATEGORIES = ['hali', 'kilim', 'paspas']

/** The openings that need room to swing into. A balcony door is a door. */
const SWINGING_TYPES = ['door', 'balcony_door']

/**
 * Whether the catalogue knows how big this actually is.
 *
 * An unmeasured variant has no footprint here and none on the server either. It is drawn as a
 * placeholder so the customer can see the seller has not given its dimensions — but it is not
 * allowed to collide with anything, because a box the size of a guess that refuses a position
 * is a refusal built on nothing.
 */
export const isMeasured = (item: LayoutItem): boolean =>
  (item.width_mm ?? 0) > 0 && (item.depth_mm ?? 0) > 0

/**
 * The space a piece occupies, with rotation applied.
 *
 * Only right angles change the footprint. At anything else the bounding square is used, which
 * is conservative rather than exact — it refuses positions a careful fit would allow, and
 * never allows two pieces to pass through each other. That is the right direction for a
 * delivery nobody can undo.
 */
export function footprintOf(item: LayoutItem, rotationDeg?: number): { width: number, depth: number } {
  if (!isMeasured(item)) {
    return { width: 0, depth: 0 }
  }

  const width = item.width_mm ?? 0
  const depth = item.depth_mm ?? 0

  const angle = (((rotationDeg ?? item.rotation_y_deg) % 360) + 360) % 360

  if (angle === 90 || angle === 270) {
    return { width: depth, depth: width }
  }

  if (angle === 0 || angle === 180) {
    return { width, depth }
  }

  const side = Math.max(width, depth)

  return { width: side, depth: side }
}

/**
 * Where a piece stands, as a rectangle.
 *
 * The position is the item's centre rather than a corner, because rotation is about the
 * centre and a corner-anchored box jumps across the room the moment somebody turns it.
 *
 * `at` lets the drag ask "where would it be if I dropped it here" without moving anything.
 */
export function rectangleOf(
  item: LayoutItem,
  at?: { x: number, z: number, rotation?: number },
): Rect {
  const footprint = footprintOf(item, at?.rotation)

  const x = at?.x ?? item.position_x_mm
  const z = at?.z ?? item.position_z_mm

  const halfWidth = Math.trunc(footprint.width / 2)
  const halfDepth = Math.trunc(footprint.depth / 2)

  return { x1: x - halfWidth, z1: z - halfDepth, x2: x + halfWidth, z2: z + halfDepth }
}

export function overlaps(a: Rect, b: Rect, tolerance = TOUCH_TOLERANCE_MM): boolean {
  return a.x1 < b.x2 - tolerance
    && a.x2 > b.x1 + tolerance
    && a.z1 < b.z2 - tolerance
    && a.z2 > b.z1 + tolerance
}

export function insideRoom(rect: Rect, geometry: RoomGeometry): boolean {
  return rect.x1 >= -TOUCH_TOLERANCE_MM
    && rect.z1 >= -TOUCH_TOLERANCE_MM
    && rect.x2 <= geometry.width_mm + TOUCH_TOLERANCE_MM
    && rect.z2 <= geometry.length_mm + TOUCH_TOLERANCE_MM
}

export const isUnderfoot = (item: LayoutItem): boolean =>
  item.category !== null && UNDERFOOT_CATEGORIES.includes(item.category)

export const swings = (opening: RoomOpening): boolean => SWINGING_TYPES.includes(opening.type)

/**
 * The floor a door or window needs kept clear.
 *
 * Null for anything without a wall and an offset: a constraint recorded as "a radiator,
 * somewhere" cannot be turned into a position, and guessing one blocks a corner of the room
 * for no stated reason.
 *
 * The offsets run along the axis the wall lies on, from the origin — north and south from
 * x = 0, east and west from z = 0. Same as the server, which matters more than either being
 * the friendlier convention.
 */
export function clearanceRectangle(opening: RoomOpening, geometry: RoomGeometry): Rect | null {
  const { wall, offset_mm: offset, width_mm: width } = opening

  if (wall === null || offset === null || width === null) {
    return null
  }

  const depth = swings(opening) ? DOOR_CLEARANCE_MM : WINDOW_CLEARANCE_MM

  switch (wall) {
    case 'north':
      return { x1: offset, z1: 0, x2: offset + width, z2: depth }
    case 'south':
      return { x1: offset, z1: geometry.length_mm - depth, x2: offset + width, z2: geometry.length_mm }
    case 'west':
      return { x1: 0, z1: offset, x2: depth, z2: offset + width }
    case 'east':
      return { x1: geometry.width_mm - depth, z1: offset, x2: geometry.width_mm, z2: offset + width }
    default:
      return null
  }
}
