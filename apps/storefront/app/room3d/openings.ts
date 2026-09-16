import type { RoomOpening } from './types'

/**
 * What kind of door or window an opening is.
 *
 * The same list as the API's `OpeningVariant`: which kinds each type can be, what each is
 * called, and how big one usually is when it is put in from the palette. An opening read
 * before the kind was asked has none, and `variantOf` judges it by its width — a 2.1 m
 * "window" is three panes, a 1.6 m "door" has two leaves — so old rooms draw right too.
 */

export type OpeningType = 'door' | 'window' | 'balcony_door'

export type OpeningVariant = 'single' | 'double' | 'triple' | 'french_balcony' | 'single_door' | 'double_door' | 'sliding'

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
  { type: 'window', variant: 'french_balcony', label: 'Fransız balkon', width_mm: 1_200, height_mm: 2_200, sill_height_mm: 0, icon: 'M6 2h12v20H6zM12 2v20M4 14h16M6 14v8M18 14v8' },
  { type: 'door', variant: 'single_door', label: 'Tek kanat', width_mm: 900, height_mm: 2_100, sill_height_mm: 0, icon: 'M6 3h12v18H6zM14 12h1M6 21h12' },
  { type: 'door', variant: 'double_door', label: 'Çift kanat', width_mm: 1_600, height_mm: 2_100, sill_height_mm: 0, icon: 'M3 3h18v18H3zM12 3v18M9.5 12h1M13.5 12h1M3 21h18' },
  { type: 'balcony_door', variant: 'single_door', label: 'Tek kanat', width_mm: 900, height_mm: 2_200, sill_height_mm: 0, icon: 'M6 3h12v18H6zM6 12h12M14 8h1M6 21h12' },
  { type: 'balcony_door', variant: 'double_door', label: 'Çift kanat', width_mm: 1_600, height_mm: 2_200, sill_height_mm: 0, icon: 'M3 3h18v18H3zM12 3v18M3 12h18M9.5 8h1M13.5 8h1' },
  { type: 'balcony_door', variant: 'sliding', label: 'Sürgülü', width_mm: 2_400, height_mm: 2_200, sill_height_mm: 0, icon: 'M2 3h20v18H2zM12 3v18M2 21h20M15 12l3-2M15 12l3 2M9 12l-3-2M9 12l-3 2' },
]

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

  if (variant === 'french_balcony') {
    return 'Fransız balkon'
  }

  const kind = kindOf(opening.type, variant)

  return kind ? `${kind.label} ${type.toLocaleLowerCase('tr-TR')}` : type
}

/** How many leaves or panes across: what both the plan and the 3D room draw. */
export function leavesOf(variant: OpeningVariant): number {
  switch (variant) {
    case 'single':
    case 'single_door':
      return 1
    case 'triple':
      return 3
    default:
      return 2
  }
}
