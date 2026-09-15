import {
  clearanceRectangle,
  isMeasured,
  isUnderfoot,
  polygonFromRect,
  polygonInsideRoom,
  polygonOf,
  polygonsOverlap,
  swings,
} from './footprint'
import type { LayoutItem, RoomGeometry, RoomOpening } from './types'

export type CollisionState = 'ok' | 'warning' | 'blocked'

/**
 * Whether each piece is somewhere the room will take it, answered while somebody drags.
 *
 * The server decides the same question again when the layout is saved, and this is a
 * deliberate copy of that code. What it buys is the only thing that matters here: the answer
 * arrives in the same frame as the pointer move, so a sofa turns red as it touches the
 * sideboard rather than a third of a second after the customer let go of it.
 *
 * Cheap enough to run on every frame of a drag — a layout is a dozen rectangles, and a dozen
 * rectangles compared against each other is nothing next to drawing the room once.
 */
export class CollisionEngine {
  constructor(
    private geometry: RoomGeometry,
    private openings: RoomOpening[],
  ) {}

  setRoom(geometry: RoomGeometry, openings: RoomOpening[]): void {
    this.geometry = geometry
    this.openings = openings
  }

  /** @returns item id → state */
  evaluate(items: LayoutItem[]): Map<string, CollisionState> {
    const states = new Map<string, CollisionState>()

    for (const item of items) {
      states.set(item.id, this.stateOf(item, items))
    }

    return states
  }

  /**
   * What would happen if this piece were dropped here.
   *
   * Asked during a drag, so it takes a position rather than reading the item's own — nothing
   * has moved yet and nothing should, until the pointer is released.
   */
  stateAt(item: LayoutItem, items: LayoutItem[], at: { x: number, z: number, rotation?: number }): CollisionState {
    return this.stateOf(item, items, at)
  }

  // --- internals -------------------------------------------------------------

  private stateOf(
    item: LayoutItem,
    items: LayoutItem[],
    at?: { x: number, z: number, rotation?: number },
  ): CollisionState {
    // A piece the catalogue has never measured cannot be checked against anything. It is
    // drawn as a placeholder and left alone; refusing a position on the strength of a guessed
    // size is refusing it on the strength of nothing.
    if (!isMeasured(item)) {
      return 'ok'
    }

    // The exact outline, turned as the piece is turned. Two pieces at an angle touch when
    // their outlines do — not when the boxes round them do, which is what made a sofa on the
    // diagonal refuse a table in the corner it never reached.
    const shape = polygonOf(item, at)

    // Through a wall is not a warning. Nothing can be delivered to a position outside the
    // room, and a client that produced one has a bug.
    if (!polygonInsideRoom(shape, this.geometry)) {
      return 'blocked'
    }

    for (const other of items) {
      if (other.id === item.id || !isMeasured(other)) {
        continue
      }

      // One of them is off the floor, or one of them is a rug.
      if (item.position_y_mm > 0 || other.position_y_mm > 0) {
        continue
      }

      if (isUnderfoot(item) || isUnderfoot(other)) {
        continue
      }

      if (polygonsOverlap(shape, polygonOf(other))) {
        return 'blocked'
      }
    }

    for (const opening of this.openings) {
      const span = clearanceRectangle(opening, this.geometry)

      if (span === null || !polygonsOverlap(shape, polygonFromRect(span))) {
        continue
      }

      // A blocked doorway is a refusal; a covered window is something to be told about.
      return swings(opening) ? 'blocked' : 'warning'
    }

    return 'ok'
  }
}
