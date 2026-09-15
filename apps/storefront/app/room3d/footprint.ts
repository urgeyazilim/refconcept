import type { LayoutItem, RoomGeometry, RoomOpening, WallName } from './types'

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
 * The categories that hang on a wall rather than stand on the floor.
 *
 * They are placed differently — flush to the nearest wall, facing the room, sliding along it
 * rather than across the floor — and they collide with nothing: a picture above a sofa and a
 * curtain behind one are the ordinary arrangement, not an overlap.
 */
const WALL_MOUNTED_CATEGORIES = ['tablo', 'ayna', 'duvar-aydinlatma', 'perde']

/** Where the bottom of a wall-hung piece goes when nobody has said, by category. */
const WALL_MOUNT_HEIGHTS_MM: Record<string, number> = {
  'tablo': 1_200,
  'ayna': 1_000,
  'duvar-aydinlatma': 1_700,
  'perde': 0,
}

export const isWallMounted = (item: LayoutItem): boolean =>
  item.category !== null && WALL_MOUNTED_CATEGORIES.includes(item.category)

export const wallMountHeight = (category: string | null): number =>
  category === null ? 0 : (WALL_MOUNT_HEIGHTS_MM[category] ?? 1_200)

/** The wall a point is nearest to, which is where a wall-hung piece dragged there goes. */
export function nearestWall(x: number, z: number, geometry: RoomGeometry): WallName {
  // A pointer outside the room is treated as being at the wall it went past, not as being
  // nearer the far wall because the distance went negative.
  const px = Math.min(Math.max(x, 0), geometry.width_mm)
  const pz = Math.min(Math.max(z, 0), geometry.length_mm)

  const distances: Array<[WallName, number]> = [
    ['west', px],
    ['east', geometry.width_mm - px],
    ['north', pz],
    ['south', geometry.length_mm - pz],
  ]

  let best = distances[0]!

  for (const candidate of distances) {
    if (candidate[1] < best[1]) {
      best = candidate
    }
  }

  return best[0]
}

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
 * The space a piece occupies, with rotation applied, as an axis-aligned box.
 *
 * Exact at right angles. At any other angle it is the box the turned rectangle fits in —
 * conservative, and the right thing for everything that reasons in boxes: the distance to a
 * wall, the snap to a neighbour's edge, the clamp inside the room. Whether two turned pieces
 * actually touch is a different question, answered exactly by {@see polygonsOverlap}.
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

  const radians = (angle * Math.PI) / 180
  const cos = Math.abs(Math.cos(radians))
  const sin = Math.abs(Math.sin(radians))

  return {
    width: Math.round(width * cos + depth * sin),
    depth: Math.round(width * sin + depth * cos),
  }
}

/** A point on the floor plan, in millimetres. */
export interface Point {
  x: number
  z: number
}

/** The four corners of a piece, in order round the outline. */
export type Polygon = [Point, Point, Point, Point]

/**
 * Where a piece stands, exactly: its rectangle turned about its centre.
 *
 * This is what decides whether two pieces touch. A sofa at thirty degrees to the wall and
 * a table tucked into the corner its box would cover do not collide, and a planner that said
 * they did would be refusing the arrangement people make on purpose. The turn is the same one
 * the scene applies — clockwise seen from above, +x towards +z — so the outline matches the
 * mesh rather than its mirror image.
 */
export function polygonOf(item: LayoutItem, at?: { x: number, z: number, rotation?: number }): Polygon {
  const x = at?.x ?? item.position_x_mm
  const z = at?.z ?? item.position_z_mm
  const angle = (((at?.rotation ?? item.rotation_y_deg) % 360) + 360) % 360

  const halfWidth = (item.width_mm ?? 0) / 2
  const halfDepth = (item.depth_mm ?? 0) / 2

  const radians = (angle * Math.PI) / 180
  const cos = Math.cos(radians)
  const sin = Math.sin(radians)

  const corner = (dx: number, dz: number): Point => ({
    x: Math.round(x + dx * cos - dz * sin),
    z: Math.round(z + dx * sin + dz * cos),
  })

  return [
    corner(-halfWidth, -halfDepth),
    corner(halfWidth, -halfDepth),
    corner(halfWidth, halfDepth),
    corner(-halfWidth, halfDepth),
  ]
}

export function polygonFromRect(rect: Rect): Polygon {
  return [
    { x: rect.x1, z: rect.z1 },
    { x: rect.x2, z: rect.z1 },
    { x: rect.x2, z: rect.z2 },
    { x: rect.x1, z: rect.z2 },
  ]
}

/**
 * The directions along which two rectangles could be apart: each one's two edge normals.
 *
 * Separating-axis theorem, for the one shape it is trivial on. Two convex outlines are apart
 * if and only if some edge normal of either separates their projections.
 */
function separatingAxes(a: Polygon, b: Polygon): Point[] {
  const axes: Point[] = []

  for (const polygon of [a, b]) {
    for (const index of [0, 1]) {
      const from = polygon[index]!
      const to = polygon[index + 1]!
      const length = Math.hypot(to.x - from.x, to.z - from.z)

      if (length > 0) {
        // The normal of the edge, unit length.
        axes.push({ x: -(to.z - from.z) / length, z: (to.x - from.x) / length })
      }
    }
  }

  return axes
}

function projection(polygon: Polygon, axis: Point): { min: number, max: number } {
  let min = Number.POSITIVE_INFINITY
  let max = Number.NEGATIVE_INFINITY

  for (const point of polygon) {
    const along = point.x * axis.x + point.z * axis.z

    min = Math.min(min, along)
    max = Math.max(max, along)
  }

  return { min, max }
}

/** Whether two outlines share floor, allowing the tolerance that snapping produces. */
export function polygonsOverlap(a: Polygon, b: Polygon, tolerance = TOUCH_TOLERANCE_MM): boolean {
  for (const axis of separatingAxes(a, b)) {
    const pa = projection(a, axis)
    const pb = projection(b, axis)

    if (pa.max <= pb.min + tolerance || pb.max <= pa.min + tolerance) {
      return false
    }
  }

  return true
}

/**
 * Every way of moving `a` off `b`: two per separating axis, shortest first.
 *
 * Along each axis the piece can leave either way — back the way it came, or right through
 * and out the other side. Both are offered, because the short way is sometimes into a wall
 * and the long way is then the only way. For two pieces square to the room that is the four
 * familiar pushes: left, right, front, back. The shortest of all is the classic minimum
 * translation vector.
 */
export function pushOutCandidates(a: Polygon, b: Polygon): Array<{ dx: number, dz: number, distance: number }> {
  const candidates: Array<{ dx: number, dz: number, distance: number }> = []

  for (const axis of separatingAxes(a, b)) {
    const pa = projection(a, axis)
    const pb = projection(b, axis)

    // Out along -axis, until a's far edge meets b's near edge; and out along +axis.
    for (const [distance, sign] of [[pa.max - pb.min, -1], [pb.max - pa.min, 1]] as const) {
      if (distance <= 0) {
        continue
      }

      candidates.push({
        dx: Math.round(axis.x * distance * sign),
        dz: Math.round(axis.z * distance * sign),
        distance,
      })
    }
  }

  return candidates.sort((first, second) => first.distance - second.distance)
}

export function polygonInsideRoom(polygon: Polygon, geometry: RoomGeometry): boolean {
  return polygon.every(point =>
    point.x >= -TOUCH_TOLERANCE_MM
    && point.z >= -TOUCH_TOLERANCE_MM
    && point.x <= geometry.width_mm + TOUCH_TOLERANCE_MM
    && point.z <= geometry.length_mm + TOUCH_TOLERANCE_MM,
  )
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

/**
 * Where a piece stands when it is put flat against a wall, facing the room.
 *
 * Pure arithmetic, out here rather than in the editor, because it is the part that can be
 * quietly wrong. The subtlety: the piece is turned first and then measured with the footprint
 * it has *after* turning. A 2200 × 900 sofa against the east wall stands 900 deep across the
 * room, and offsetting it by the width it had before the turn puts half of it through the
 * wall — a position the collision rules then refuse, on an alignment the customer asked for
 * by name.
 *
 * The gap is for a skirting board. Flush against the plane of the wall looks wrong in a
 * render and is not where furniture actually sits.
 */
export function againstWall(
  item: LayoutItem,
  wall: WallName,
  geometry: RoomGeometry,
  gapMm: number,
): { position_x_mm: number, position_z_mm: number, rotation_y_deg: number } {
  // Rotation is clockwise seen from above, from facing +z (south): the scene turns a piece
  // by −rotation about y and every model's front is +z, so 270 faces east and 90 faces west.
  // A piece against a wall looks into the room.
  const rotation = { north: 0, south: 180, west: 270, east: 90 }[wall]

  const turned = footprintOf(item, rotation)

  const position = {
    position_x_mm: item.position_x_mm,
    position_z_mm: item.position_z_mm,
    rotation_y_deg: rotation,
  }

  switch (wall) {
    case 'north':
      position.position_z_mm = Math.trunc(turned.depth / 2) + gapMm
      break
    case 'south':
      position.position_z_mm = geometry.length_mm - Math.trunc(turned.depth / 2) - gapMm
      break
    case 'west':
      position.position_x_mm = Math.trunc(turned.width / 2) + gapMm
      break
    case 'east':
      position.position_x_mm = geometry.width_mm - Math.trunc(turned.width / 2) - gapMm
      break
  }

  return position
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
