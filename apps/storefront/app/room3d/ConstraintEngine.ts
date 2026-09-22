import {
  againstWall,
  footprintOf,
  isMeasured,
  isUnderfoot,
  isWallMounted,
  nearestWall,
  polygonOf,
  polygonsOverlap,
  pushOutCandidates,
  type Polygon,
} from './footprint'
import type { LayoutItem, RoomGeometry, RoomOpening } from './types'

/**
 * Where a piece actually ends up when somebody tries to put it somewhere it cannot go.
 *
 * `settled` is false when no position could be found — the room is full, or the piece is
 * wider than the room — and `x`/`z` are then the fallback the caller supplied, which is where
 * the piece was before anybody touched it.
 *
 * `rotation` is set only when the constraint decided it: a wall-hung piece faces the wall it
 * was put on, whatever way it was pointing before.
 */
export interface Settled {
  x: number
  z: number
  settled: boolean
  rotation?: number
}

/**
 * Keeps furniture inside the room and out of each other.
 *
 * The first editor only *flagged* a bad position — the piece turned red and stayed where the
 * pointer had put it, half through a wall. The product owner's verdict on that was short:
 * "odanın dışına çıkabiliyor, duvarların içine girebiliyor; ben bu şekilde istemedim". The
 * storyboard had said it from the start — "ürünler duvarların içine giremez" — and a red box
 * through a wall is not that.
 *
 * So this is a constraint rather than a check. Given where somebody wants a piece, it answers
 * where the piece can be: clamped inside the walls, pushed out of whatever it overlaps by the
 * shortest distance, and — when there is nowhere — left where it was. A drag stops at the wall
 * and slides along it, which is what every planner anyone has used does.
 *
 * The collision engine still runs afterwards and the server still decides. With this in front
 * of them, "blocked" on the client means a bug here, and "blocked" from the server means a
 * client that bypassed this — both are things to be told about, neither is ordinary.
 */
export class ConstraintEngine {
  /**
   * How many times a piece may be pushed before the position is declared impossible.
   *
   * Each push resolves one overlap and may create another — out of the sofa and into the
   * sideboard — so a few passes are normal. A dozen is a room with no space for the thing.
   */
  private static readonly MAX_PASSES = 12

  constructor(
    private geometry: RoomGeometry,
    private openings: RoomOpening[],
  ) {}

  setRoom(geometry: RoomGeometry, openings: RoomOpening[]): void {
    this.geometry = geometry
    this.openings = openings
  }

  /**
   * The nearest position to `at` that the room will take.
   *
   * @param fallback where the piece stands now, returned when nothing else is possible
   */
  settle(
    item: LayoutItem,
    items: LayoutItem[],
    at: { x: number, z: number, rotation?: number },
    fallback?: { x: number, z: number },
  ): Settled {
    // An unmeasured piece has no footprint to keep anywhere. It is drawn as a placeholder
    // the customer can see is unmeasured; refusing it a position on a guessed size would be
    // a refusal built on nothing.
    if (!isMeasured(item)) {
      return { x: at.x, z: at.z, settled: true }
    }

    if (isWallMounted(item)) {
      return this.hang(item, at)
    }

    const rotation = at.rotation ?? item.rotation_y_deg
    const blockers = this.blockersFor(item, items)

    let x = at.x
    let z = at.z

    for (let pass = 0; pass < ConstraintEngine.MAX_PASSES; pass++) {
      const clamped = this.clampToRoom(item, x, z, rotation)

      x = clamped.x
      z = clamped.z

      const shape = polygonOf(item, { x, z, rotation })
      const collided = blockers.find(blocker => polygonsOverlap(shape, blocker))

      if (collided === undefined) {
        return { x, z, settled: true }
      }

      const push = this.shortestPushOut(item, shape, collided, rotation)

      x += push.dx
      z += push.dz
    }

    // Nowhere to put it. Back where it was — or, if there is no "was", the last clamped guess,
    // said plainly to be unsettled so the caller can refuse the move.
    return fallback === undefined
      ? { x, z, settled: false }
      : { x: fallback.x, z: fallback.z, settled: false }
  }

  // --- internals -------------------------------------------------------------

  /**
   * A wall-hung piece goes on the nearest wall, facing the room, and slides along it.
   *
   * Dragged across the floor it does not float there: it lands flush on whichever wall is
   * closest to where the pointer is, at the point along that wall the pointer is level with.
   * Nothing on the floor is in its way — it is on the wall — so there is nothing to push
   * out of, only the wall's ends to stay inside.
   */
  private hang(item: LayoutItem, at: { x: number, z: number }): Settled {
    const wall = nearestWall(at.x, at.z, this.geometry)
    const hung = againstWall(item, wall, this.geometry, 0)
    const turned = footprintOf(item, hung.rotation_y_deg)

    const halfWidth = Math.trunc(turned.width / 2)
    const halfDepth = Math.trunc(turned.depth / 2)

    // Along the wall the pointer decides; across it the wall does.
    const alongX = wall === 'north' || wall === 'south'

    return {
      x: alongX ? clamp(at.x, halfWidth, this.geometry.width_mm - halfWidth) : hung.position_x_mm,
      z: alongX ? hung.position_z_mm : clamp(at.z, halfDepth, this.geometry.length_mm - halfDepth),
      rotation: hung.rotation_y_deg,
      settled: true,
    }
  }

  /**
   * Inside the walls, footprint and all.
   *
   * The footprint is the *rotated* one: a 2200 × 900 sofa turned to face east stands 900 wide
   * across the room, and clamping it by the width it had before the turn leaves half of it in
   * the wall. A piece wider than the room is centred, which is the least wrong answer.
   */
  private clampToRoom(item: LayoutItem, x: number, z: number, rotation: number): { x: number, z: number } {
    const footprint = footprintOf(item, rotation)

    const halfWidth = Math.trunc(footprint.width / 2)
    const halfDepth = Math.trunc(footprint.depth / 2)

    return {
      x: clamp(x, halfWidth, this.geometry.width_mm - halfWidth),
      z: clamp(z, halfDepth, this.geometry.length_mm - halfDepth),
    }
  }

  /**
   * Everything this piece may not stand on: the other floor-standing pieces, and that is all.
   *
   * A door's swing used to be in here, and being in here means the room refuses: drag an
   * armchair in front of the balcony glass and it slid away, every time, with nothing said.
   * The product owner tried it and could not work out what was stopping them.
   *
   * Two things were wrong. A door that opens onto the balcony takes no floor in the room, and
   * a sliding one takes none anywhere — the rule never asked which way anything opened. And
   * the rule itself is guidance rather than geometry: nine hundred millimetres in front of a
   * door is what a designer leaves, not something the world enforces, and somebody who wants
   * the armchair by the glass has a reason for it. A sofa inside a wall is impossible; a
   * chair in a doorway is a decision.
   *
   * So it is told, not prevented — {@see CollisionEngine} colours it and the panel says why.
   * Pieces still cannot be put through each other or through a wall, which is not advice.
   */
  private blockersFor(item: LayoutItem, items: LayoutItem[]): Polygon[] {
    const blockers: Polygon[] = []

    const raised = item.position_y_mm > 0
    const underfoot = isUnderfoot(item)

    if (!raised && !underfoot) {
      for (const other of items) {
        if (other.id === item.id || !isMeasured(other) || other.position_y_mm > 0 || isUnderfoot(other)) {
          continue
        }

        blockers.push(polygonOf(other))
      }
    }

    return blockers
  }

  /**
   * The smallest move that takes the piece off `blocker` and keeps it in the room.
   *
   * One candidate per separating axis — the two edge directions of each outline — and the
   * shortest wins, which is what makes a drag feel like sliding along the sofa rather than
   * jumping over it. For two pieces square to the walls that is out to the left, right, front
   * or back; for a turned piece the candidates are turned with it.
   *
   * A push that would leave the room is not a way out: the wall pushes straight back, the
   * blocker pushes out again, and the piece oscillates until the passes run out. So a
   * candidate that ends outside is skipped while any other exists. Against a door's swing
   * that is the whole difference — the short way out is *into* the wall, and the right way
   * out is further into the room.
   */
  private shortestPushOut(item: LayoutItem, shape: Polygon, blocker: Polygon, rotation: number): { dx: number, dz: number } {
    const candidates = pushOutCandidates(shape, blocker)

    if (candidates.length === 0) {
      return { dx: 0, dz: 0 }
    }

    const footprint = footprintOf(item, rotation)
    const halfWidth = Math.trunc(footprint.width / 2)
    const halfDepth = Math.trunc(footprint.depth / 2)
    const centreX = (shape[0].x + shape[2].x) / 2
    const centreZ = (shape[0].z + shape[2].z) / 2

    const staysInside = (candidate: { dx: number, dz: number }): boolean =>
      centreX + candidate.dx - halfWidth >= 0
      && centreX + candidate.dx + halfWidth <= this.geometry.width_mm
      && centreZ + candidate.dz - halfDepth >= 0
      && centreZ + candidate.dz + halfDepth <= this.geometry.length_mm

    // Sorted shortest first already; the first that stays inside wins.
    const best = candidates.find(staysInside) ?? candidates[0]!

    return { dx: best.dx, dz: best.dz }
  }
}

function clamp(value: number, min: number, max: number): number {
  if (min > max) {
    return Math.trunc((min + max) / 2)
  }

  return Math.min(Math.max(value, min), max)
}
