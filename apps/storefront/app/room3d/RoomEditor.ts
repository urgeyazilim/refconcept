import { Vector3 } from 'three'

import { CollisionEngine, type CollisionState } from './CollisionEngine'
import { ConstraintEngine } from './ConstraintEngine'
import { DragController } from './DragController'
import { OpeningDragController } from './OpeningDragController'
import { GizmoController } from './GizmoController'
import { type Measurement, MeasurementEngine, formatDistance } from './MeasurementEngine'
import { SceneManager } from './SceneManager'
import { SnapEngine } from './SnapEngine'
import { againstWall, footprintOf, isWallMounted, wallMountHeight } from './footprint'
import { type LayoutItem, type RoomGeometry, type RoomOpening, type ViewMode, type WallName, toMm, toUnits } from './types'

/** A measurement, already placed on the screen, for the HTML overlay to draw. */
export interface OverlayLabel {
  id: string
  x: number
  y: number
  text: string
  towards: string
}

/** Everything a component needs to redraw its panels after something changed. */
export interface EditorState {
  items: LayoutItem[]
  states: Map<string, CollisionState>
  selectedId: string | null
  measurements: Measurement[]
  canUndo: boolean
  canRedo: boolean
  /** True between a change and the moment it has been written to the server. */
  unsaved: boolean
}

/** Where a piece stands. The only part of an item a layout edit ever changes. */
interface Placement {
  position_x_mm: number
  position_z_mm: number
  rotation_y_deg: number
  locked: boolean
}

export interface RoomEditorOptions {
  onChange: (state: EditorState) => void
  onOverlay: (labels: OverlayLabel[]) => void
  /** Called after edits have settled. Absent in read-only contexts. */
  onPersist?: (items: LayoutItem[]) => void
  /** A door or window was dragged and let go on a wall. Absent in read-only contexts. */
  onMoveOpening?: (id: string, offsetMm: number, wall: WallName) => void
}

/**
 * The editor: one room, its furniture, and everything somebody can do to it.
 *
 * This is the piece that owns the truth while the page is open. The Vue component around it
 * renders panels and buttons and holds no layout state of its own — it is handed a fresh
 * {@link EditorState} whenever anything changes. Keeping it this way is what stops the room
 * and the sidebar from ever disagreeing about where the sofa is, which is the bug every
 * planner written the other way around eventually has.
 *
 * Editing is on placements only. Nothing here adds a product to the layout that the customer
 * did not choose, and nothing changes what a product *is* — a plan that invents furniture is
 * exactly what went wrong in the render pipeline, and a plan that quietly resizes a sofa to
 * make it fit is the same mistake with better manners.
 */
export class RoomEditor {
  private readonly scene: SceneManager

  private readonly collisions: CollisionEngine

  /** Keeps every move inside the walls and out of other pieces; runs before collisions do. */
  private readonly constraints: ConstraintEngine

  private readonly snaps: SnapEngine

  private readonly measurements: MeasurementEngine

  private readonly drag: DragController

  /** Doors and windows, picked up and put on a wall. Only when the room may be edited. */
  private readonly openingDrag: OpeningDragController | null

  /** The move and turn handles on the selected piece. */
  private readonly gizmo: GizmoController

  /**
   * Where the gizmo has the selected piece right now, held inside the room, before release.
   *
   * The gizmo moves the group freely; every change is read back, held, and written to the
   * group again. Nothing reaches the layout until the handle is let go.
   */
  private gizmoPreview: { x: number, z: number, rotation: number } | null = null

  private items: LayoutItem[] = []

  private states = new Map<string, CollisionState>()

  private selectedId: string | null = null

  /**
   * The past and the future, as whole-layout snapshots.
   *
   * Snapshots rather than a list of operations: a layout is a few dozen small records, and an
   * undo stack of diffs is a second implementation of every edit — the one that has to be
   * able to run backwards. The one time that goes wrong it goes wrong silently, days later,
   * on a customer's saved plan.
   */
  private past: Array<Map<string, Placement>> = []

  private future: Array<Map<string, Placement>> = []

  /** Bounded, because a long session is thousands of drags and none of them are precious. */
  private static readonly HISTORY_LIMIT = 60

  /** How far off the wall an aligned piece stands: enough for a skirting board. */
  private static readonly WALL_GAP_MM = 60

  /** How long after the last edit the layout is written. */
  private static readonly AUTOSAVE_MS = 1200

  private saveTimer: ReturnType<typeof setTimeout> | null = null

  private unsaved = false

  constructor(
    canvas: HTMLCanvasElement,
    private geometry: RoomGeometry,
    private openings: RoomOpening[],
    private readonly options: RoomEditorOptions,
  ) {
    this.scene = new SceneManager(canvas)
    this.collisions = new CollisionEngine(geometry, openings)
    this.constraints = new ConstraintEngine(geometry, openings)
    this.snaps = new SnapEngine(geometry)
    this.measurements = new MeasurementEngine(geometry)

    this.scene.setRoom(geometry, openings)
    this.scene.onFrame(() => this.publishOverlay())

    this.gizmo = new GizmoController(canvas, this.scene.scene, {
      camera: () => this.scene.cameras.active,
      setOrbitEnabled: enabled => this.scene.cameras.setOrbitEnabled(enabled),
      onChange: () => this.onGizmoChange(),
      onCommit: () => this.onGizmoCommit(),
      invalidate: () => this.scene.invalidate(),
    })

    /*
     * Registered before the furniture drag, so a press on a door is the door's: the opening
     * controller stops the event from reaching the furniture controller, which would
     * otherwise deselect whatever was selected under it.
     */
    this.openingDrag = options.onMoveOpening === undefined
      ? null
      : new OpeningDragController(canvas, {
          openings: () => this.openings,
          roomObjects: () => this.scene.roomObjects(),
          walls: () => this.scene.walls(),
          camera: () => this.scene.cameras.active,
          setOrbitEnabled: enabled => this.scene.cameras.setOrbitEnabled(enabled),
          onPreview: (id, wall, offsetMm) => {
            this.scene.rebuildRoom(this.openings.map(opening => (opening.id === id ? { ...opening, wall, offset_mm: offsetMm } : opening)))
          },
          onCommit: (id, wall, offsetMm) => {
            this.openings = this.openings.map(opening => (opening.id === id ? { ...opening, wall, offset_mm: offsetMm } : opening))
            this.setRoom(this.geometry, this.openings)
            options.onMoveOpening?.(id, offsetMm, wall)
          },
          onCancel: () => this.scene.rebuildRoom(this.openings),
        })

    this.drag = new DragController(canvas, {
      gizmoActive: () => this.gizmo.isActive() || (this.openingDrag?.isDragging() ?? false),
      items: () => this.items,
      pickable: () => this.scene.pickable(),
      camera: () => this.scene.cameras.active,
      setOrbitEnabled: enabled => this.scene.cameras.setOrbitEnabled(enabled),
      onSelect: id => this.select(id),
      onHover: id => this.scene.setHover(id, this.items, this.states, this.selectedId),
      onPreview: (id, at, state, guides) => {
        const item = this.find(id)

        if (item === undefined) {
          return
        }

        this.scene.previewItem(item, at, state)
        this.scene.setGuides(guides)
      },
      onCommit: (id, at) => this.moveTo(id, at),
      onCancel: () => {
        // Put the piece back where it was; the preview moved it and nothing was saved.
        this.scene.setGuides([])
        this.scene.setItems(this.items, this.states, this.selectedId)
      },
      /*
       * Snap first, then hold. The snap puts the piece edge to edge with a neighbour or a
       * wall; the constraint then refuses anything through a wall or into another piece and
       * slides it to the nearest place it fits. The guides are shown only if the snapped
       * position survived — a guide pointing at a position the piece is not at is a lie.
       */
      snap: (item, at) => {
        const snapped = this.snaps.snap(item, this.items, at)
        const held = this.constraints.settle(item, this.items, snapped, {
          x: item.position_x_mm,
          z: item.position_z_mm,
        })

        return {
          x: held.x,
          z: held.z,
          rotation: held.rotation,
          guides: held.x === snapped.x && held.z === snapped.z ? snapped.guides : [],
        }
      },
      stateAt: (item, at) => this.collisions.stateAt(item, this.items, at),
    })
  }

  // --- the room --------------------------------------------------------------

  /** The room as the photographs measured it, instead of as we drew it. */
  showScan(url: string): Promise<void> {
    return this.scene.showScan(url)
  }

  hideScan(): void {
    this.scene.hideScan()
  }

  setRoom(geometry: RoomGeometry, openings: RoomOpening[]): void {
    this.geometry = geometry
    this.openings = openings

    this.collisions.setRoom(geometry, openings)
    this.constraints.setRoom(geometry, openings)
    this.snaps.setRoom(geometry)
    this.measurements.setRoom(geometry)

    this.scene.setRoom(geometry, openings)
    this.reevaluate()
  }

  /**
   * Replaces the furniture wholesale.
   *
   * Used when a layout is loaded or the AI proposes one. It clears the history rather than
   * appending to it: undo after "apply the AI's layout" should be the customer's own layout
   * back, not the AI's plan halfway assembled.
   */
  setItems(items: LayoutItem[]): void {
    this.items = items.map(item => ({ ...item }))
    this.past = []
    this.future = []
    this.selectedId = null

    /*
     * Anything saved somewhere the room will not take it is brought back in.
     *
     * A layout written before the constraints existed can hold a sofa two metres outside the
     * west wall — the product owner's own room did — and a rule that only guards new moves
     * leaves it there forever. Each piece is settled where it stands: inside the walls, off
     * the others, and if the room genuinely has no space, where it was. What moved is saved.
     */
    let healed = false

    for (const item of this.items) {
      const held = this.constraints.settle(item, this.items, { x: item.position_x_mm, z: item.position_z_mm })

      if (held.settled && (held.x !== item.position_x_mm || held.z !== item.position_z_mm || (held.rotation !== undefined && held.rotation !== item.rotation_y_deg))) {
        item.position_x_mm = held.x
        item.position_z_mm = held.z
        item.rotation_y_deg = held.rotation ?? item.rotation_y_deg
        healed = true
      }
    }

    this.reevaluate()

    if (healed) {
      this.schedulePersist()
    }
  }

  setView(mode: ViewMode): void {
    this.scene.setView(mode)
    // The plan view is a different camera, and handles drawn for the old one point nowhere.
    this.gizmo.syncCamera()
  }

  /** Shift held: the turn handle stops snapping to fifteen degrees. */
  setFreeRotation(free: boolean): void {
    this.gizmo.setFreeRotation(free)
  }

  // --- editing ---------------------------------------------------------------

  select(id: string | null): void {
    this.selectedId = id

    this.scene.setGuides([])
    this.scene.setItems(this.items, this.states, this.selectedId)
    this.syncGizmo()

    this.publish()
  }

  /**
   * Handles on whichever piece is selected, unless it is pinned.
   *
   * Called wherever the selection or the scene's pieces may have changed — a select, an add,
   * an undo, a removal — rather than only from select(): a piece added from the catalogue is
   * selected without going through select(), and the first version of this attached nothing
   * to it. A locked piece shows no handles, which is what locked means.
   */
  private syncGizmo(): void {
    const selected = this.selectedId === null ? undefined : this.find(this.selectedId)

    this.gizmo.attach(
      selected === undefined || selected.locked ? null : (this.scene.pieceFor(selected.id) ?? null),
    )
  }

  /**
   * The gizmo moved the selected piece; hold it and draw it there.
   *
   * The handle put the group wherever the pointer said. That position is read back in
   * millimetres, snapped and held exactly as a drag would be, and the group is put where it
   * is allowed to be — so a piece pushed at a wall stops at the wall with the arrow still in
   * the customer's hand. A turn that would put one end through a wall is nudged clear, or
   * refused and the group turned back.
   */
  private onGizmoChange(): void {
    const group = this.gizmo.attached()
    const item = this.selectedId === null ? undefined : this.find(this.selectedId)
    const mode = this.gizmo.activeMode()

    if (group === null || item === undefined || mode === null) {
      return
    }

    const current = this.gizmoPreview ?? {
      x: item.position_x_mm,
      z: item.position_z_mm,
      rotation: item.rotation_y_deg,
    }

    if (mode === 'rotate') {
      // The scene turns the other way round; see FurnitureBuilder.place().
      const degrees = Math.round((((-group.rotation.y * 180) / Math.PI) % 360 + 360) % 360)

      const held = this.constraints.settle(
        item,
        this.items,
        { x: current.x, z: current.z, rotation: degrees },
        { x: current.x, z: current.z },
      )

      this.gizmoPreview = held.settled
        ? { x: held.x, z: held.z, rotation: degrees }
        : current
    }
    else {
      const desired = { x: Math.round(toMm(group.position.x)), z: Math.round(toMm(group.position.z)) }

      const snapped = this.snaps.snap(item, this.items, desired)
      const held = this.constraints.settle(
        item,
        this.items,
        { x: snapped.x, z: snapped.z, rotation: current.rotation },
        { x: current.x, z: current.z },
      )

      this.gizmoPreview = { x: held.x, z: held.z, rotation: held.rotation ?? current.rotation }

      this.scene.setGuides(held.x === snapped.x && held.z === snapped.z ? snapped.guides : [])
    }

    this.scene.previewItem(item, this.gizmoPreview, this.collisions.stateAt(item, this.items, this.gizmoPreview))
  }

  /** The handle was released: whatever the preview holds becomes the layout. */
  private onGizmoCommit(): void {
    const preview = this.gizmoPreview
    const id = this.selectedId

    this.gizmoPreview = null
    this.scene.setGuides([])

    if (preview === null || id === null) {
      return
    }

    this.edit(id, (piece) => {
      piece.position_x_mm = preview.x
      piece.position_z_mm = preview.z
      piece.rotation_y_deg = preview.rotation
    })
  }

  /** Moves a piece, having already decided where. The drag's commit path. */
  moveTo(id: string, at: { x: number, z: number, rotation?: number }): void {
    this.edit(id, (item) => {
      item.position_x_mm = at.x
      item.position_z_mm = at.z

      // A wall-hung piece faces whichever wall it landed on.
      if (at.rotation !== undefined) {
        item.rotation_y_deg = at.rotation
      }
    })

    this.scene.setGuides([])
  }

  /**
   * Turns a piece by a quarter turn, or by whatever step is asked for.
   *
   * Quarter turns because furniture in a room is square to the walls almost always, and
   * because a rotation off a right angle makes the footprint conservative — the piece starts
   * refusing positions it would actually fit in.
   */
  rotate(id: string, deltaDeg: number): void {
    const item = this.find(id)

    if (item === undefined || item.locked) {
      return
    }

    const rotation = (((item.rotation_y_deg + deltaDeg) % 360) + 360) % 360

    /*
     * A turned piece has a different footprint, and a sofa turned beside a wall now has one
     * end in it. It is nudged clear if it can be; if the room has no room for it that way
     * round, the turn does not happen — better a sofa that will not turn than one in a wall.
     */
    const held = this.constraints.settle(
      item,
      this.items,
      { x: item.position_x_mm, z: item.position_z_mm, rotation },
      { x: item.position_x_mm, z: item.position_z_mm },
    )

    if (!held.settled) {
      return
    }

    this.edit(id, (piece) => {
      piece.rotation_y_deg = rotation
      piece.position_x_mm = held.x
      piece.position_z_mm = held.z
    })
  }

  /**
   * Nudges a piece by a fixed step, for the arrow keys.
   *
   * A pointer cannot place something to the centimetre at a normal zoom, and "10 mm to the
   * left" is a thing people genuinely want once the room is nearly right.
   */
  nudge(id: string, dx: number, dz: number): void {
    const item = this.find(id)

    if (item === undefined || item.locked) {
      return
    }

    // Held like a drag is: an arrow key pressed against a wall does nothing, rather than
    // walking the piece through it a centimetre at a time.
    const held = this.constraints.settle(
      item,
      this.items,
      { x: item.position_x_mm + dx, z: item.position_z_mm + dz },
      { x: item.position_x_mm, z: item.position_z_mm },
    )

    if (held.x === item.position_x_mm && held.z === item.position_z_mm) {
      return
    }

    this.edit(id, (piece) => {
      piece.position_x_mm = held.x
      piece.position_z_mm = held.z
    })
  }

  /**
   * Pins a piece so nothing moves it — including the AI layout engine.
   *
   * The reason this exists at all: somebody has a television on a wall with the aerial socket
   * behind it, and no layout proposal, however good, is allowed to move it.
   */
  /**
   * Pushes a piece back against the nearest wall and turns it to face the room.
   *
   * The single most common correction somebody makes by hand, and the one a pointer is
   * worst at: "against the wall" is a position no drag ever quite reaches, and a sideboard
   * 30 mm off the wall looks like a mistake in every render made from the layout afterwards.
   *
   * Nearest wall rather than a chosen one, because the customer has already put it roughly
   * where they mean it. This is a tidy-up, not a decision.
   */
  alignToWall(id: string): void {
    const item = this.find(id)

    if (item === undefined) {
      return
    }

    /*
     * The nearest wall, not a chosen one.
     *
     * The customer has already put the piece roughly where they mean it; this is a tidy-up
     * rather than a decision, and asking which wall would be asking them to say again what
     * they have just said with the drag.
     */
    const distances: Array<{ wall: WallName, gap: number }> = [
      { wall: 'north', gap: item.position_z_mm },
      { wall: 'south', gap: this.geometry.length_mm - item.position_z_mm },
      { wall: 'west', gap: item.position_x_mm },
      { wall: 'east', gap: this.geometry.width_mm - item.position_x_mm },
    ]

    distances.sort((a, b) => a.gap - b.gap)

    const nearest = distances[0]

    if (nearest === undefined) {
      return
    }

    const placed = againstWall(item, nearest.wall, this.geometry, RoomEditor.WALL_GAP_MM)

    this.edit(id, (moved) => {
      moved.position_x_mm = placed.position_x_mm
      moved.position_z_mm = placed.position_z_mm
      moved.rotation_y_deg = placed.rotation_y_deg
    })
  }

  /** Puts a piece in the middle of the room, keeping the way it faces. */
  centreInRoom(id: string): void {
    this.edit(id, (item) => {
      item.position_x_mm = Math.round(this.geometry.width_mm / 2)
      item.position_z_mm = Math.round(this.geometry.length_mm / 2)
    })
  }

  /**
   * Another one of the same product, beside it.
   *
   * A pair of bedside tables, four dining chairs, two armchairs facing each other: the
   * catalogue search has already been done and doing it again to place the second one is
   * work nobody should be asked for twice.
   */
  duplicate(id: string): LayoutItem | null {
    const item = this.find(id)

    if (item === undefined) {
      return null
    }

    const copy: LayoutItem = { ...item, id: crypto.randomUUID(), locked: false }

    this.add(copy)

    return copy
  }

  /**
   * How high off the floor a piece hangs.
   *
   * For pictures, mirrors, wall shelves and televisions. Anything above the floor is out of
   * the way of everything on it, which is why the collision rules exempt it — and why this
   * is the control that turns a box standing in the middle of the room into a picture on a
   * wall.
   */
  setHeight(id: string, millimetres: number): void {
    this.edit(id, (item) => {
      // Floor to just under three metres: below zero is under the floor, and above that is
      // a ceiling in almost every home this will ever run in.
      item.position_y_mm = Math.max(0, Math.min(2_900, Math.round(millimetres)))
    })
  }

  toggleLock(id: string): void {
    // allowLocked, and this is the only caller that passes it: a locked piece refuses every
    // other edit, and a lock that cannot be undone is furniture welded to the floor.
    this.edit(id, (item) => {
      item.locked = !item.locked
    }, true)
  }

  remove(id: string): void {
    this.remember()

    this.items = this.items.filter(item => item.id !== id)

    if (this.selectedId === id) {
      this.selectedId = null
    }

    this.reevaluate()
    this.schedulePersist()
  }

  /**
   * Adds a product the customer picked from the catalogue, somewhere it fits.
   *
   * Somewhere it fits rather than the middle of the room, because the middle of a furnished
   * room is usually the coffee table. A new piece that lands on top of something and turns
   * red is a piece the customer has to rescue before they can even look at it, and the first
   * thing the editor did was tell them off.
   *
   * It is still only a starting position. They will move it.
   */
  add(item: LayoutItem): void {
    this.remember()

    /*
     * A position of nothing means nobody has chosen one.
     *
     * The page asks the server where a product belongs — the same rules that arrange a whole
     * design, so a bookcase goes against a wall rather than into the first free rectangle in
     * the middle of the floor. When that fails, or for anything added without a position, the
     * search below is the fallback: a worse position rather than a lost product.
     */
    const chosen = item.position_x_mm !== 0 || item.position_z_mm !== 0

    let placed = chosen ? { ...item } : { ...item, ...this.freeSpotFor(item) }

    /*
     * A picture goes on a wall, at picture height, whatever the floor search said.
     *
     * The server's placement and the free-spot search both think in floor space. A wall-hung
     * piece is hung instead: on the nearest wall to wherever they put it, facing the room, its
     * bottom at the height its kind is usually hung — unless somebody already set one.
     */
    if (isWallMounted(placed)) {
      const hung = this.constraints.settle(placed, this.items, { x: placed.position_x_mm, z: placed.position_z_mm })

      placed = {
        ...placed,
        position_x_mm: hung.x,
        position_z_mm: hung.z,
        rotation_y_deg: hung.rotation ?? placed.rotation_y_deg,
        position_y_mm: placed.position_y_mm > 0 ? placed.position_y_mm : wallMountHeight(placed.category),
      }
    }

    this.items = [...this.items, placed]
    this.selectedId = placed.id

    this.reevaluate()
    this.schedulePersist()
  }

  /**
   * The first position on a coarse grid where a piece is not in anything's way.
   *
   * Coarse on purpose — 250 mm steps, a few dozen candidates — because this runs once when
   * somebody adds a product and an exhaustive search would be slower to no visible benefit.
   * Falls back to the middle of the room, where at least it is obvious and easy to grab.
   */
  private freeSpotFor(item: LayoutItem): { position_x_mm: number, position_z_mm: number } {
    const centre = {
      position_x_mm: Math.round(this.geometry.width_mm / 2),
      position_z_mm: Math.round(this.geometry.length_mm / 2),
    }

    const footprint = footprintOf(item)

    if (footprint.width === 0) {
      return centre
    }

    const step = 250

    // Inset by half the piece, so a candidate is never a position half outside the room.
    const fromX = Math.trunc(footprint.width / 2)
    const fromZ = Math.trunc(footprint.depth / 2)

    const candidates: Array<{ x: number, z: number, distance: number }> = []

    for (let z = fromZ; z <= this.geometry.length_mm - fromZ; z += step) {
      for (let x = fromX; x <= this.geometry.width_mm - fromX; x += step) {
        candidates.push({
          x,
          z,
          distance: (x - centre.position_x_mm) ** 2 + (z - centre.position_z_mm) ** 2,
        })
      }
    }

    /*
     * Nearest the middle of the room first.
     *
     * Scanning corner to corner finds a free spot just as well and puts every new product in
     * the same corner, one behind the other, as far from where somebody is looking as the
     * room allows. What they meant by "add this" is "show me this here".
     */
    candidates.sort((a, b) => a.distance - b.distance)

    for (const candidate of candidates) {
      if (this.collisions.stateAt(item, this.items, candidate) === 'ok') {
        return { position_x_mm: candidate.x, position_z_mm: candidate.z }
      }
    }

    return centre
  }

  undo(): void {
    const previous = this.past.pop()

    if (previous === undefined) {
      return
    }

    this.future.push(this.placements())
    this.apply(previous)
  }

  redo(): void {
    const next = this.future.pop()

    if (next === undefined) {
      return
    }

    this.past.push(this.placements())
    this.apply(next)
  }

  /** The canvas as a PNG, for the render pipeline and for thumbnails. */
  /** Flies the camera in to the selected piece, or back out to the whole room. */
  focusSelected(): void {
    const item = this.selectedId === null ? undefined : this.find(this.selectedId)

    if (item === undefined) {
      this.scene.cameras.reframe()
      this.scene.invalidate()

      return
    }

    const size = Math.max(item.width_mm ?? 1_000, item.depth_mm ?? 1_000, item.height_mm ?? 800)

    this.scene.cameras.focusOn(
      new Vector3(toUnits(item.position_x_mm), toUnits(item.position_y_mm + (item.height_mm ?? 800) / 2), toUnits(item.position_z_mm)),
      toUnits(size) * 2.2,
    )
    this.scene.invalidate()
  }

  /** Flies back out to the whole room. */
  frameRoom(): void {
    this.scene.cameras.reframe()
    this.scene.invalidate()
  }

  /** The doors and windows as the editor has them now, for the browser tests. */
  openingsNow(): RoomOpening[] {
    return this.openings
  }

  /**
   * Where a point on a wall is on the screen, in CSS pixels: `alongMm` along the wall's own
   * axis, `heightMm` above the floor. For the browser tests, which have to take hold of a
   * door somewhere and let go of it somewhere else.
   */
  wallScreenPoint(wall: WallName, alongMm: number, heightMm: number): { x: number, y: number } | null {
    const point = wall === 'north'
      ? { x: alongMm, y: heightMm, z: 0 }
      : wall === 'south'
        ? { x: alongMm, y: heightMm, z: this.geometry.length_mm }
        : wall === 'west'
          ? { x: 0, y: heightMm, z: alongMm }
          : { x: this.geometry.width_mm, y: heightMm, z: alongMm }

    return this.scene.projectToScreen(point)
  }

  /** Where an opening's middle is on the screen, or null when it cannot be placed. */
  openingScreenPoint(id: string): { x: number, y: number } | null {
    const opening = this.openings.find(candidate => candidate.id === id)

    if (opening === undefined || opening.wall === null || opening.offset_mm === null || opening.width_mm === null) {
      return null
    }

    const height = (opening.sill_height_mm ?? 0) + (opening.height_mm ?? 2_000) / 2

    return this.wallScreenPoint(opening.wall, opening.offset_mm + opening.width_mm / 2, height)
  }

  snapshot(): string {
    return this.scene.snapshot()
  }

  /** The same frame as a depth map, for a renderer that must follow the room rather than read it. */
  depthSnapshot(): string {
    return this.scene.depthSnapshot()
  }

  dispose(): void {
    // Anything edited in the last second and a bit is written now rather than lost, because
    // "I moved it and closed the tab" is the most ordinary way to leave a page there is.
    this.flush()

    this.drag.dispose()
    this.openingDrag?.dispose()
    this.gizmo.dispose()
    this.scene.dispose()
  }

  // --- internals -------------------------------------------------------------

  private find(id: string): LayoutItem | undefined {
    return this.items.find(item => item.id === id)
  }

  /**
   * One edit: remember where things were, change one piece, redraw, schedule a save.
   *
   * Every mutation goes through here so none of them can forget the history or the save. A
   * locked piece is refused rather than silently allowed — the lock exists precisely so that
   * something else cannot move it.
   */
  private edit(id: string, mutate: (item: LayoutItem) => void, allowLocked = false): void {
    const item = this.find(id)

    if (item === undefined || (item.locked && !allowLocked)) {
      return
    }

    this.remember()

    const changed = { ...item }
    mutate(changed)

    this.items = this.items.map(existing => (existing.id === id ? changed : existing))

    this.reevaluate()
    this.schedulePersist()
  }

  /** Pushes the current placements onto the undo stack and drops the redo stack. */
  private remember(): void {
    this.past.push(this.placements())

    if (this.past.length > RoomEditor.HISTORY_LIMIT) {
      this.past.shift()
    }

    // A new edit is a new timeline. Keeping the redo stack across one is how somebody gets a
    // piece back in a position they never put it in.
    this.future = []
  }

  private placements(): Map<string, Placement> {
    return new Map(this.items.map(item => [item.id, {
      position_x_mm: item.position_x_mm,
      position_z_mm: item.position_z_mm,
      rotation_y_deg: item.rotation_y_deg,
      locked: item.locked,
    }]))
  }

  private apply(placements: Map<string, Placement>): void {
    this.items = this.items
      // A piece that was added after this snapshot is not in it, and undoing an addition is
      // taking it away again.
      .filter(item => placements.has(item.id))
      .map((item) => {
        const placement = placements.get(item.id)

        return placement === undefined ? item : { ...item, ...placement }
      })

    this.reevaluate()
    this.schedulePersist()
  }

  private reevaluate(): void {
    this.states = this.collisions.evaluate(this.items)

    this.scene.setItems(this.items, this.states, this.selectedId)
    this.syncGizmo()
    this.publish()
  }

  private publish(): void {
    const selected = this.selectedId === null ? undefined : this.find(this.selectedId)

    this.options.onChange({
      items: this.items,
      states: this.states,
      selectedId: this.selectedId,
      measurements: selected === undefined ? [] : this.measurements.measure(selected, this.items),
      canUndo: this.past.length > 0,
      canRedo: this.future.length > 0,
      unsaved: this.unsaved,
    })

    this.publishOverlay()
  }

  /**
   * Puts the selected piece's gaps on the screen.
   *
   * Recomputed after every frame, which sounds expensive and is not: frames are only drawn
   * when something has moved, and the arithmetic is four rectangles and four projections.
   */
  private publishOverlay(): void {
    const selected = this.selectedId === null ? undefined : this.find(this.selectedId)

    const labels: OverlayLabel[] = this.wallLabels()

    if (selected === undefined) {
      this.options.onOverlay(labels)

      return
    }

    for (const [index, measurement] of this.measurements.measure(selected, this.items).entries()) {
      const middle = {
        x: (measurement.from.x + measurement.to.x) / 2,
        z: (measurement.from.z + measurement.to.z) / 2,
      }

      const point = this.scene.projectToScreen(middle)

      if (point === null) {
        continue
      }

      labels.push({
        id: `${selected.id}-${index}`,
        x: point.x,
        y: point.y,
        text: formatDistance(measurement.mm),
        towards: measurement.towards,
      })
    }

    this.options.onOverlay(labels)
  }

  /**
   * The walls named, at the top of each, so the plan and the room agree on which is which.
   *
   * Not inside the room — from in there the customer can see which wall is which — and never
   * for a wall the camera is looking through, because a label floating where a hidden wall
   * would be is a label pointing at air.
   */
  private wallLabels(): OverlayLabel[] {
    if (this.scene.cameras.mode === 'inside') {
      return []
    }

    const { width_mm: width, length_mm: length, height_mm: height } = this.geometry

    const walls: Array<{ id: WallName, text: string, x: number, z: number }> = [
      { id: 'north', text: 'Kuzey', x: width / 2, z: 0 },
      { id: 'south', text: 'Güney', x: width / 2, z: length },
      { id: 'west', text: 'Batı', x: 0, z: length / 2 },
      { id: 'east', text: 'Doğu', x: width, z: length / 2 },
    ]

    const labels: OverlayLabel[] = []

    for (const wall of walls) {
      if (!this.scene.wallVisible(wall.id)) {
        continue
      }

      const point = this.scene.projectToScreen({ x: wall.x, y: height, z: wall.z })

      if (point === null) {
        continue
      }

      labels.push({ id: `wall-${wall.id}`, x: point.x, y: point.y, text: wall.text, towards: 'wall' })
    }

    return labels
  }

  /**
   * Saves a short while after the last change.
   *
   * Not on every change: a drag is sixty changes a second and each one is a request. Not on a
   * button either — a plan somebody spent twenty minutes on and lost to a closed tab is a
   * plan they do not make again.
   */
  private schedulePersist(): void {
    if (this.options.onPersist === undefined) {
      return
    }

    this.unsaved = true

    if (this.saveTimer !== null) {
      clearTimeout(this.saveTimer)
    }

    this.saveTimer = setTimeout(() => this.flush(), RoomEditor.AUTOSAVE_MS)
  }

  private flush(): void {
    if (this.saveTimer !== null) {
      clearTimeout(this.saveTimer)
      this.saveTimer = null
    }

    if (!this.unsaved || this.options.onPersist === undefined) {
      return
    }

    this.unsaved = false
    this.options.onPersist(this.items)
  }
}
