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
/** What the floor is drawn as. Three textures; anything the analysis could not place is null. */
export type FloorMaterial = 'wood' | 'tile' | 'carpet'

export interface RoomGeometry {
  id: string
  width_mm: number
  length_mm: number
  height_mm: number
  /** From the room photograph's analysis, when there was one. Boards when there was not. */
  floor?: FloorMaterial | null
  /**
   * What the photograph said the room is painted, as "#rrggbb".
   *
   * The reading has always described the surfaces and the planner drew every room in the
   * same cream regardless, so a grey room with a white cornice came out white on white and
   * the customer could not recognise their own room. Null keeps the default.
   */
  wall_color?: string | null
  ceiling_color?: string | null
  /** Whether the photograph showed a cornice. Drawn only when it did. */
  crown_molding?: boolean | null
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
  /** Which kind: single, double, triple, french_balcony, single_door, double_door, sliding. Null when never asked. */
  variant?: string | null
  /** A door's hinge jamb and direction: start_in, end_in, start_out, end_out. Null means start_in. */
  swing?: string | null
  wall: WallName | null
  offset_mm: number | null
  width_mm: number | null
  height_mm: number | null
  /** Height of the sill above the floor. Zero for a door, roughly 900 for a window. */
  sill_height_mm: number | null
  /**
   * Who put it there.
   *
   * 'ai' is the reading's own answer and a later reading may replace it; 'user' is anything
   * the customer wrote, dragged or retyped, and nothing replaces that.
   */
  source?: 'ai' | 'user'
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
  /** What it costs now; null when the room has no price for it (a test fixture, an old row). */
  price?: { amount_minor: number, currency: string, formatted: string } | null
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
