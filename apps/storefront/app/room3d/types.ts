/**
 * The shapes the 3D layer works in.
 *
 * Millimetres and integers everywhere, matching the API exactly. Three.js wants metres as
 * floats and gets them at one boundary — {@link MM_PER_UNIT} — rather than at fifty call
 * sites, because a scene that converts in both directions in several places is a scene where
 * a sofa is eventually a thousand times too big and nobody can find the multiplication.
 */

/**
 * One scene unit is one metre, and everything the server says is millimetres.
 *
 * Metres rather than millimetres as the scene unit because Three.js's defaults — camera near
 * and far planes, light falloff, shadow bias — are all tuned for objects a few units across.
 * A room modelled at 4850 units wide renders with z-fighting on every wall.
 */
export const MM_PER_UNIT = 1000

/** Millimetres to scene units. */
export const toUnits = (mm: number): number => mm / MM_PER_UNIT

/** Scene units back to millimetres, rounded — the API stores integers. */
export const toMm = (units: number): number => Math.round(units * MM_PER_UNIT)

/** The walls, named from inside the room looking at them. */
export type WallName = 'north' | 'south' | 'east' | 'west'

/**
 * The room's measurements, as confirmed by the customer.
 *
 * `width` runs along x and `length` along z, with the origin at the corner where the north
 * and west walls meet. Every position in a layout is measured from there.
 */
export interface RoomGeometry {
  id: string
  width_mm: number
  length_mm: number
  height_mm: number
}

/**
 * A door, window or fixture, as a span along one wall.
 *
 * **`offset_mm` runs along the axis the wall lies on, from the origin.** North and south are
 * measured from x = 0, east and west from z = 0. Not "from the left seen from inside", which
 * sounds friendlier and reverses direction on two of the four walls — a window 720 mm along
 * the south wall then lands 720 mm from the opposite corner, which looks like a mirrored
 * model rather than an arithmetic mistake and survives a screenshot review easily.
 *
 * The server assumes the same. The two agreeing matters more than either being natural.
 */
export interface RoomOpening {
  id: string
  type: string
  wall: WallName | null
  offset_mm: number | null
  width_mm: number | null
  height_mm: number | null
  /** Height of the sill above the floor. Zero for a door, roughly 900 for a window. */
  sill_height_mm: number | null
}

/** One product standing somewhere in the room. */
export interface LayoutItem {
  id: string
  product_id: string
  sku_id: string
  name: string
  category: string | null
  position_x_mm: number
  position_y_mm: number
  position_z_mm: number
  rotation_y_deg: number
  locked: boolean
  collision_state: 'ok' | 'warning' | 'blocked'
  /** The piece's own size. Without all three the scene falls back to a category default. */
  width_mm: number | null
  height_mm: number | null
  depth_mm: number | null
  /** A photograph. Its dominant colour is what the placeholder shape is drawn in. */
  image_url: string | null
  /** A glTF or GLB, when the catalogue has one. Most of it does not, yet. */
  model_url: string | null
  /**
   * Where that model came from: the seller, or a mesh generated from the photograph.
   *
   * The editor says so on screen for a generated one. Its far side was never photographed,
   * and somebody walking round the back of a sofa should know they are looking at a guess
   * rather than at the thing they are about to buy.
   */
  model_source?: 'seller' | 'ai' | null
}

/** What the customer is looking at. */
export type ViewMode = 'perspective' | 'top' | 'inside'

/** How much of the room is drawn. */
export type DisplayMode = '3d' | 'plan' | '2d'
