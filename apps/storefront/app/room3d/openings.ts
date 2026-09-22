import type { RoomOpening, WallName } from './types'

/**
 * What kind of door or window an opening is.
 *
 * The same list as the API's `OpeningVariant`: which kinds each type can be, what each is
 * called, and how big one usually is when it is put in from the palette. An opening read
 * before the kind was asked has none, and `variantOf` judges it by its width — a 2.1 m
 * "window" is three panes, a 1.6 m "door" has two leaves — so old rooms draw right too.
 */

export type OpeningType = 'door' | 'window' | 'balcony_door'

export type OpeningVariant = 'single' | 'double' | 'triple' | 'fixed' | 'awning' | 'french_balcony' | 'single_door' | 'double_door' | 'sliding' | 'folding'

export interface OpeningKind {
  type: OpeningType
  variant: OpeningVariant
  label: string
  /** Starting size, in millimetres, for one put in from the palette. */
  width_mm: number
  height_mm: number
  sill_height_mm: number
  /** A 24 × 24 line icon. */
  icon: string
}

export const OPENING_TYPES: readonly OpeningType[] = ['door', 'window', 'balcony_door']

export const TYPE_LABELS: Record<OpeningType, string> = { door: 'Kapı', window: 'Pencere', balcony_door: 'Balkon kapısı' }

/** Every kind on offer, grouped the way the palette shows them. */
export const OPENING_KINDS: readonly OpeningKind[] = [
  { type: 'window', variant: 'single', label: 'Tek kanat', width_mm: 900, height_mm: 1_400, sill_height_mm: 900, icon: 'M5 4h14v16H5zM5 12h14' },
  { type: 'window', variant: 'double', label: 'Çift kanat', width_mm: 1_400, height_mm: 1_400, sill_height_mm: 900, icon: 'M3 4h18v16H3zM12 4v16M3 12h18' },
  { type: 'window', variant: 'triple', label: 'Üçlü', width_mm: 2_100, height_mm: 1_400, sill_height_mm: 900, icon: 'M2 5h20v14H2zM8.7 5v14M15.3 5v14' },
  // Sealed: one pane, no sash line, no handle. The picture window over a stair, the fixed
  // light beside a front door — glass that is part of the wall rather than a way to open it.
  { type: 'window', variant: 'fixed', label: 'Sabit cam', width_mm: 1_200, height_mm: 1_600, sill_height_mm: 800, icon: 'M3 4h18v16H3zM6 7l12 10' },
  // A vasistas: small, high, hinged at the top. Over a door, in a kitchen, in a bathroom —
  // and the one kind whose sill is well above eye level, which is why it is drawn small here.
  { type: 'window', variant: 'awning', label: 'Vasistas', width_mm: 700, height_mm: 500, sill_height_mm: 1_800, icon: 'M3 8h18v8H3zM3 8l18 4' },
  { type: 'window', variant: 'french_balcony', label: 'Fransız balkon', width_mm: 1_200, height_mm: 2_200, sill_height_mm: 0, icon: 'M6 2h12v20H6zM12 2v20M4 14h16M6 14v8M18 14v8' },
  { type: 'door', variant: 'single_door', label: 'Tek kanat', width_mm: 900, height_mm: 2_100, sill_height_mm: 0, icon: 'M6 3h12v18H6zM14 12h1M6 21h12' },
  { type: 'door', variant: 'double_door', label: 'Çift kanat', width_mm: 1_600, height_mm: 2_100, sill_height_mm: 0, icon: 'M3 3h18v18H3zM12 3v18M9.5 12h1M13.5 12h1M3 21h18' },
  // An interior door with nowhere to swing. Common in a flat, and the reason somebody draws
  // a door at all is usually the clearance a swing needs — so the kind that needs none matters.
  { type: 'door', variant: 'sliding', label: 'Sürgülü', width_mm: 900, height_mm: 2_100, sill_height_mm: 0, icon: 'M3 3h18v18H3zM12 3v18M3 21h18M15 12l3-2M15 12l3 2' },
  { type: 'balcony_door', variant: 'single_door', label: 'Tek kanat', width_mm: 900, height_mm: 2_200, sill_height_mm: 0, icon: 'M6 3h12v18H6zM6 12h12M14 8h1M6 21h12' },
  { type: 'balcony_door', variant: 'double_door', label: 'Çift kanat', width_mm: 1_600, height_mm: 2_200, sill_height_mm: 0, icon: 'M3 3h18v18H3zM12 3v18M3 12h18M9.5 8h1M13.5 8h1' },
  { type: 'balcony_door', variant: 'sliding', label: 'Sürgülü', width_mm: 2_400, height_mm: 2_200, sill_height_mm: 0, icon: 'M2 3h20v18H2zM12 3v18M2 21h20M15 12l3-2M15 12l3 2M9 12l-3-2M9 12l-3 2' },
  // Concertina: four narrow panels that fold back against the jamb and open the whole wall.
  { type: 'balcony_door', variant: 'folding', label: 'Katlanır', width_mm: 3_000, height_mm: 2_200, sill_height_mm: 0, icon: 'M2 3h20v18H2zM7 3v18M12 3v18M17 3v18M2 21h20' },
]

/**
 * The four walls in the order they go round, clockwise seen from above.
 *
 * The room is always drawn the same way — the wall at the top of the plan is the one the
 * model calls north — and which of them actually faces north is a separate fact about the
 * building. Everything here turns one into the other.
 */
export const WALLS_CLOCKWISE: readonly WallName[] = ['north', 'east', 'south', 'west']

/**
 * What to call a drawn wall, given which drawn wall really faces north.
 *
 * The reading names the walls from photographs and gets it wrong often enough to be worth a
 * control: a window facing east recorded as west makes every sentence the planner writes
 * about light backwards. Nothing moves when this changes — no measurement, no opening, no
 * piece of furniture. Four labels do.
 *
 * Both walls are drawn walls: `drawn` is the one being named, `north` is whichever one the
 * customer says faces north. The offset between them, going clockwise, is the answer.
 */
export function compassName(drawn: WallName, north: WallName): WallName {
  const from = WALLS_CLOCKWISE.indexOf(north)
  const at = WALLS_CLOCKWISE.indexOf(drawn)

  if (from < 0 || at < 0) {
    return drawn
  }

  return WALLS_CLOCKWISE[(at - from + 4) % 4] ?? drawn
}

export function kindsFor(type: OpeningType): OpeningKind[] {
  return OPENING_KINDS.filter(kind => kind.type === type)
}

export function kindOf(type: string, variant: string | null | undefined): OpeningKind | null {
  return OPENING_KINDS.find(kind => kind.type === type && kind.variant === variant) ?? null
}

/**
 * The opening's kind, judged by its width when nobody recorded one.
 *
 * Openings read from photographs before the kind was asked have no variant. Rather than
 * draw every one of them as a single pane, the width decides: casements are rarely wider
 * than a metre, a door leaf rarely wider than 1.2 m.
 */
export function variantOf(opening: Pick<RoomOpening, 'type' | 'variant' | 'width_mm' | 'sill_height_mm'>): OpeningVariant {
  if (opening.variant && kindOf(opening.type, opening.variant)) {
    return opening.variant as OpeningVariant
  }

  const width = opening.width_mm ?? 0

  if (opening.type === 'window') {
    if ((opening.sill_height_mm ?? 900) === 0 && (opening.width_mm ?? 0) > 0) {
      return 'french_balcony'
    }

    // High and small is a vasistas; nothing else sits well above eye level.
    if ((opening.sill_height_mm ?? 0) >= 1_600 && width > 0 && width <= 900) {
      return 'awning'
    }

    return width < 1_000 ? 'single' : width < 1_800 ? 'double' : 'triple'
  }

  if (opening.type === 'balcony_door' && width >= 2_000) {
    return 'sliding'
  }

  return width >= 1_300 ? 'double_door' : 'single_door'
}

/** What the customer reads: "Çift kanat pencere", "Sürgülü balkon kapısı". */
export function describeKind(opening: Pick<RoomOpening, 'type' | 'variant' | 'width_mm' | 'sill_height_mm'>): string {
  const type = TYPE_LABELS[opening.type as OpeningType] ?? opening.type
  const variant = variantOf(opening)

  // Named things rather than "<kind> <type>": nobody says "sabit cam pencere".
  if (variant === 'french_balcony' || variant === 'awning' || variant === 'fixed') {
    return kindOf(opening.type, variant)?.label ?? type
  }

  const kind = kindOf(opening.type, variant)

  return kind ? `${kind.label} ${type.toLocaleLowerCase('tr-TR')}` : type
}

/**
 * Which way a door goes.
 *
 * The jamb is named along the wall's own axis — `start` is the jamb at the lower offset —
 * because "left" reverses on two walls out of four. `hingeIsLeft` turns it into what the
 * customer sees from inside the room.
 */
export type DoorSwing = 'start_in' | 'end_in' | 'start_out' | 'end_out' | 'top_hung'

export function swingOf(opening: Pick<RoomOpening, 'swing'>): DoorSwing {
  const swing = opening.swing

  return swing === 'end_in' || swing === 'start_out' || swing === 'end_out' || swing === 'top_hung'
    ? swing
    : 'start_in'
}

export const opensIn = (swing: DoorSwing): boolean => swing === 'start_in' || swing === 'end_in' || swing === 'top_hung'

export const hingeAtStart = (swing: DoorSwing): boolean => swing === 'start_in' || swing === 'start_out'

/** Whether it hangs on a jamb at all: a top-hung sash is hinged along its top edge. */
export const hasJamb = (swing: DoorSwing): boolean => swing !== 'top_hung'

/**
 * Which ways an opening of this kind can go, in the order a customer would read them.
 *
 * A window can be top-hung — a vasistas, a tilt sash, a fanlight — which has no jamb and
 * sweeps no floor: it takes the air above whatever is under it, which is why one goes over a
 * door or behind a kitchen counter where a casement would be in the way. It is the difference
 * between a window somebody can open with the sofa where it is and one they cannot.
 *
 * A door hangs on a jamb. A doorway with a fanlight over it is two openings, not one door
 * that opens two ways.
 */
export function swingsFor(type: OpeningType): Array<{ swing: DoorSwing, label: string }> {
  if (type === 'window') {
    return [
      { swing: 'start_in', label: 'İçeri açılır' },
      { swing: 'start_out', label: 'Dışarı açılır' },
      { swing: 'top_hung', label: 'Üstten açılır' },
    ]
  }

  return [
    { swing: 'start_in', label: 'İçeri açılır' },
    { swing: 'start_out', label: 'Dışarı açılır' },
  ]
}

/** The same direction, hung on whichever jamb this one is not on. */
export function keepingJamb(from: DoorSwing, to: DoorSwing): DoorSwing {
  if (to === 'top_hung') {
    return to
  }

  return hingeAtStart(from) ? to : otherJamb(to)
}

/**
 * Whether the hinge is on the left as seen from inside the room. Facing the north wall the
 * axis runs left to right, so the start jamb is on the left; facing south it runs the other
 * way. On the east wall the start (north end) is on the left; on the west it is on the right.
 */
export function hingeIsLeft(wall: WallName | null, swing: DoorSwing): boolean {
  const startIsLeft = wall === 'north' || wall === 'east'

  return hingeAtStart(swing) === startIsLeft
}

/** The swing with the hinge moved to the other jamb. */
export function otherJamb(swing: DoorSwing): DoorSwing {
  return opensIn(swing) ? (hingeAtStart(swing) ? 'end_in' : 'start_in') : (hingeAtStart(swing) ? 'end_out' : 'start_out')
}

/** The swing opening the other way. */
export function otherWay(swing: DoorSwing): DoorSwing {
  if (swing === 'top_hung') {
    return 'start_in'
  }

  return hingeAtStart(swing) ? (opensIn(swing) ? 'start_out' : 'start_in') : (opensIn(swing) ? 'end_out' : 'end_in')
}

/** Whether the kind swings at all: a sliding door and a window do not. */
export function hasSwing(opening: Pick<RoomOpening, 'type' | 'variant' | 'width_mm' | 'sill_height_mm'>): boolean {
  /*
   * A window opens somewhere too.
   *
   * It sweeps no floor, so it never blocked anything and was never asked about — but "can I
   * open this window once the sofa is there" is a question about the room, and a sealed pane
   * is the one window where the answer is no whatever is in front of it.
   */
  if (opening.type === 'window') {
    return variantOf(opening) !== 'fixed'
  }

  if (opening.type !== 'door' && opening.type !== 'balcony_door') {
    return false
  }

  // Sliding and folding doors take no floor: that is the whole reason somebody fits one, and
  // drawing a swing arc for them would put a clearance rule on a door that has none.
  const variant = variantOf(opening)

  return variant !== 'sliding' && variant !== 'folding'
}

/** How many leaves or panes across: what both the plan and the 3D room draw. */
export function leavesOf(variant: OpeningVariant): number {
  switch (variant) {
    // A sealed pane and a top-hung sash are each one sheet of glass with no mullion in it.
    case 'single':
    case 'single_door':
    case 'fixed':
    case 'awning':
      return 1
    case 'triple':
      return 3
    case 'folding':
      return 4
    default:
      return 2
  }
}
