import { type Camera, type Mesh, type Object3D, Raycaster, Vector2 } from 'three'

import { type RoomOpening, toMm, type WallName } from './types'

/**
 * What the opening drag needs from the scene, and what it tells it.
 */
export interface OpeningDragDelegate {
  openings: () => RoomOpening[]
  /** Everything in the room group: walls with their casings, glass and skirting. */
  roomObjects: () => Object3D[]
  /** The four walls, each carrying `userData.wall`. */
  walls: () => Mesh[]
  camera: () => Camera
  setOrbitEnabled: (enabled: boolean) => void
  /** Every pointer move: where the opening would be now. */
  onPreview: (id: string, wall: WallName, offsetMm: number) => void
  /** On release, when it moved. */
  onCommit: (id: string, wall: WallName, offsetMm: number) => void
  /** On release, when it did not. */
  onCancel: (id: string) => void
  /** The opening under a resting pointer changed: this one, or none. */
  onHover: (id: string | null) => void
  /** What the pointer should look like; an empty string hands it back to whoever else is asking. */
  setCursor: (cursor: string) => void
  /** A press landed on this opening, or on nothing. */
  onSelect: (id: string | null) => void
}

/**
 * Picking a door or a window up in the 3D room and putting it on a wall.
 *
 * The customer does not type where the door is; they take hold of it and put it there. The
 * press finds the opening by walking up from whatever casing or glass was hit; the move
 * casts against the walls themselves, so the opening follows the pointer onto whichever wall
 * it is over — the same wall or another — and sits where the pointer is along it. The
 * corners stop it. Release saves; a release with no movement saves nothing.
 */
export class OpeningDragController {
  private readonly raycaster = new Raycaster()

  private readonly pointer = new Vector2()

  private dragging: {
    opening: RoomOpening
    wall: WallName
    offsetMm: number
    moved: boolean
  } | null = null

  /** What the resting pointer was last over, so hover is reported on change only. */
  private hovered: string | null = null

  /** A hover test waiting for the next frame: a raycast per pixel is a raycast wasted. */
  private hoverPending = 0

  private readonly handlers: Array<[keyof HTMLElementEventMap, (event: never) => void]>

  constructor(
    private readonly canvas: HTMLCanvasElement,
    private readonly delegate: OpeningDragDelegate,
  ) {
    const down = (event: PointerEvent) => this.onPointerDown(event)
    const move = (event: PointerEvent) => this.onPointerMove(event)
    const up = (event: PointerEvent) => this.onPointerUp(event)

    this.handlers = [
      ['pointerdown', down as (event: never) => void],
      ['pointermove', move as (event: never) => void],
      ['pointerup', up as (event: never) => void],
      ['pointercancel', up as (event: never) => void],
    ]

    for (const [name, handler] of this.handlers) {
      this.canvas.addEventListener(name, handler as EventListener)
    }
  }

  dispose(): void {
    if (this.hoverPending !== 0) {
      cancelAnimationFrame(this.hoverPending)
      this.hoverPending = 0
    }

    for (const [name, handler] of this.handlers) {
      this.canvas.removeEventListener(name, handler as EventListener)
    }
  }

  isDragging(): boolean {
    return this.dragging !== null
  }

  // --- the gesture -----------------------------------------------------------

  private onPointerDown(event: PointerEvent): void {
    if (event.button !== 0) {
      return
    }

    const opening = this.openingUnder(event)

    if (opening === undefined || opening.wall === null || opening.offset_mm === null || opening.width_mm === null) {
      return
    }

    /*
     * Picked as well as picked up.
     *
     * A door could be dragged and nothing else: its kind, its measurements and which way it
     * opens all lived in a list down the side of the page, and the customer had to find the
     * right row among five to change the thing they were pointing at. Pressing it now says
     * which one they mean, and the panel comes to the door rather than the other way round.
     */
    this.delegate.onSelect(opening.id)

    this.dragging = { opening, wall: opening.wall, offsetMm: opening.offset_mm, moved: false }

    this.delegate.setOrbitEnabled(false)
    this.delegate.setCursor('grabbing')
    this.canvas.setPointerCapture(event.pointerId)
    event.stopImmediatePropagation()
  }

  private onPointerMove(event: PointerEvent): void {
    const drag = this.dragging

    if (drag === null) {
      this.scheduleHover(event)

      return
    }

    const hit = this.wallUnder(event)

    if (hit === null || drag.opening.width_mm === null) {
      return
    }

    const width = drag.opening.width_mm
    const span = hit.spanMm
    // Centred on the pointer, inside the wall to the corners, to the centimetre.
    const offset = Math.round(Math.max(0, Math.min(span - width, hit.alongMm - width / 2)) / 10) * 10

    if (offset === drag.offsetMm && hit.wall === drag.wall) {
      return
    }

    drag.wall = hit.wall
    drag.offsetMm = offset
    drag.moved = true

    this.delegate.onPreview(drag.opening.id, drag.wall, drag.offsetMm)
  }

  private onPointerUp(event: PointerEvent): void {
    const drag = this.dragging

    if (drag === null) {
      return
    }

    this.dragging = null
    this.delegate.setOrbitEnabled(true)
    this.delegate.setCursor(this.hovered === null ? '' : 'grab')

    if (this.canvas.hasPointerCapture(event.pointerId)) {
      this.canvas.releasePointerCapture(event.pointerId)
    }

    if (drag.moved) {
      this.delegate.onCommit(drag.opening.id, drag.wall, drag.offsetMm)
    }
    else {
      this.delegate.onCancel(drag.opening.id)
    }
  }

  /**
   * The opening under the resting pointer, asked for at most once a frame.
   *
   * A door and a window could be dragged and said so nowhere. There was no hover, no cursor,
   * no highlight — the only way to learn that the door moves is a line of grey text under
   * the room, and the product owner read it and still could not tell whether they had hold
   * of anything. A thing that can be picked up has to look like one before it is touched.
   */
  private scheduleHover(event: PointerEvent): void {
    if (this.hoverPending !== 0) {
      return
    }

    const at = { clientX: event.clientX, clientY: event.clientY } as PointerEvent

    this.hoverPending = requestAnimationFrame(() => {
      this.hoverPending = 0

      const over = this.openingUnder(at)?.id ?? null

      if (over === this.hovered) {
        return
      }

      this.hovered = over
      this.delegate.onHover(over)

      // Only claims the cursor for its own: the furniture controller owns it otherwise, and
      // two controllers both writing every frame would fight over it.
      if (over !== null) {
        this.delegate.setCursor('grab')
      }
    })
  }

  // --- picking -----------------------------------------------------------------

  private aim(event: PointerEvent): void {
    const bounds = this.canvas.getBoundingClientRect()

    this.pointer.set(
      ((event.clientX - bounds.left) / bounds.width) * 2 - 1,
      -((event.clientY - bounds.top) / bounds.height) * 2 + 1,
    )

    this.raycaster.setFromCamera(this.pointer, this.delegate.camera())
  }

  /**
   * Whether a hit is on something the customer can see.
   *
   * The walls between the camera and the room are hidden so the room can be looked into,
   * but a hidden wall still stops a ray: without this, every press landed on the invisible
   * near wall and no door could be picked up, and every drag put the door on the wall
   * nearest the camera rather than the one under the pointer.
   */
  private static visible(object: Object3D): boolean {
    let current: Object3D | null = object

    while (current !== null) {
      if (!current.visible) {
        return false
      }

      current = current.parent
    }

    return true
  }

  /** The opening whose casing, leaf or glass is under the pointer. */
  private openingUnder(event: PointerEvent): RoomOpening | undefined {
    this.aim(event)

    for (const hit of this.raycaster.intersectObjects(this.delegate.roomObjects(), true)) {
      if (!OpeningDragController.visible(hit.object)) {
        continue
      }

      let object: Object3D | null = hit.object

      while (object !== null) {
        const id = object.userData.openingId as string | undefined

        if (typeof id === 'string') {
          return this.delegate.openings().find(opening => opening.id === id)
        }

        object = object.parent
      }

      // The first thing hit that is not part of an opening is a wall or the floor: the
      // press was not on a door or a window.
      return undefined
    }

    return undefined
  }

  /**
   * The wall under the pointer and how far along it the pointer is, in millimetres.
   *
   * Cast against the walls only, casings and all: a pointer over a door's own casing is over
   * that wall. The world is metres from the room's origin corner; along a north or south wall
   * that is x, along an east or west wall it is z — the same axis the plan measures on.
   */
  private wallUnder(event: PointerEvent): { wall: WallName, alongMm: number, spanMm: number } | null {
    this.aim(event)

    for (const hit of this.raycaster.intersectObjects(this.delegate.walls(), true)) {
      if (!OpeningDragController.visible(hit.object)) {
        continue
      }

      let object: Object3D | null = hit.object

      while (object !== null) {
        const wall = object.userData.wall as WallName | undefined

        if (wall !== undefined) {
          const alongMm = wall === 'north' || wall === 'south' ? toMm(hit.point.x) : toMm(hit.point.z)
          const spanMm = wall === 'north' || wall === 'south' ? this.widthMm() : this.lengthMm()

          return { wall, alongMm, spanMm }
        }

        object = object.parent
      }
    }

    return null
  }

  private widthMm(): number {
    return this.spanOf('north')
  }

  private lengthMm(): number {
    return this.spanOf('west')
  }

  /** A wall's length, read off the wall itself so the controller carries no copy of the room. */
  private spanOf(wall: WallName): number {
    const mesh = this.delegate.walls().find(candidate => candidate.userData.wall === wall)

    if (mesh === undefined) {
      return 0
    }

    mesh.geometry.computeBoundingBox()

    const box = mesh.geometry.boundingBox

    return box === null ? 0 : toMm(box.max.x - box.min.x)
  }
}
