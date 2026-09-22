import { type Camera, Plane, Raycaster, Vector2, Vector3 } from 'three'

import type { CollisionState } from './CollisionEngine'
import type { SnapGuide } from './SnapEngine'
import { type LayoutItem, toMm, toUnits } from './types'

/**
 * What the drag needs from the scene around it, and what it tells that scene back.
 *
 * An interface rather than a reference to the SceneManager so this class can be tested with a
 * plain object, and so the direction of the dependency is stated: the drag knows nothing about
 * rendering, and the scene knows nothing about pointers.
 */
export interface DragDelegate {
  items: () => LayoutItem[]
  /** Every furniture group in the scene, for the raycaster to hit. */
  pickable: () => import('three').Object3D[]
  camera: () => Camera
  /** While a drag is in progress the orbit controls must not also be moving the camera. */
  setOrbitEnabled: (enabled: boolean) => void
  onSelect: (id: string | null) => void
  /** Called on every pointer move, with a position nothing has been saved at yet. */
  onPreview: (id: string, at: { x: number, z: number, rotation?: number }, state: CollisionState, guides: SnapGuide[]) => void
  /** Called once, on release, with the position to keep. */
  onCommit: (id: string, at: { x: number, z: number, rotation?: number }) => void
  /** Called on release when the piece did not actually move. */
  onCancel: (id: string) => void
  /** Where the piece may go, given where the pointer put it — and which way it then faces, when the room decides that. */
  snap: (item: LayoutItem, at: { x: number, z: number }) => { x: number, z: number, rotation?: number, guides: SnapGuide[] }
  stateAt: (item: LayoutItem, at: { x: number, z: number, rotation?: number }) => CollisionState
  /** Whether the pointer is on the gizmo's handles, which then own the gesture. */
  gizmoActive: () => boolean
  /** The piece under a resting pointer changed: this one, or none. */
  onHover: (id: string | null) => void
  /** What the pointer should look like right now; an empty string means the page's own. */
  setCursor: (cursor: string) => void
}

/**
 * How far the pointer has to travel before a press becomes a drag.
 *
 * Four pixels. A click is never perfectly still — a trackpad tap moves one or two, and a
 * finger on glass moves more — and without a dead zone every click that happened to land on a
 * sofa nudged it by a millimetre, wrote that to the layout and pushed an entry onto a sixty
 * deep undo stack. Selecting a piece four times filled a quarter of somebody's undo history
 * with moves they did not make.
 *
 * Measured in screen pixels rather than millimetres on the floor, because it is a fact about
 * hands and not about rooms: the same wobble is a centimetre when the camera is close and half
 * a metre when it is across the room.
 */
const DRAG_THRESHOLD_PX = 4

/**
 * Picking a piece up and putting it somewhere else.
 *
 * The whole interaction is one gesture — press, move, release — and the three parts have to
 * agree about which piece and where, which is why they live in one class rather than three
 * event handlers sharing module state.
 *
 * Two details that are not obvious and cost an afternoon each:
 *
 * The pointer is tracked against a horizontal plane at the piece's own height, not the floor.
 * Dragging a picture hung at 1.5 m along the floor plane makes it slide across the room far
 * faster than the pointer, because the camera is looking down and the ray meets the floor
 * much further away than it meets the picture.
 *
 * The grab keeps its offset. Without it the piece's centre jumps to the pointer the instant
 * it is touched, so a sofa grabbed by its arm leaps half a metre before it has been dragged
 * anywhere, and the customer has lost the position they started from.
 */
export class DragController {
  private readonly raycaster = new Raycaster()

  private readonly pointer = new Vector2()

  private readonly plane = new Plane(new Vector3(0, 1, 0), 0)

  private readonly hit = new Vector3()

  /**
   * The press in progress, before anybody knows what it is.
   *
   * A press on a piece is not yet a drag and a press on the floor is not yet a deselect; both
   * become themselves only once the pointer has travelled far enough, or been released. It
   * used to be decided on the way down, and both halves were wrong: a click nudged the sofa it
   * selected, and turning the camera by dragging from an empty patch of floor threw away the
   * selection and the gizmo with it.
   */
  private pressed: {
    pointerId: number
    item: LayoutItem | undefined
    /** Where on the screen it went down, for the dead zone. */
    screenX: number
    screenY: number
    offsetX: number
    offsetZ: number
    startX: number
    startZ: number
    /** Past the dead zone: this is a drag, and a release will write a position. */
    dragging: boolean
  } | null = null

  /**
   * Whether the camera should have the next gesture whatever it lands on.
   *
   * Held by the page while the space bar is down. Without it a piece of furniture is a hole in
   * the camera: press anywhere on the sofa and the view will not turn, which in a room with a
   * large sofa in the middle of it is most of the screen.
   */
  private cameraWanted = false

  private readonly handlers: Array<[keyof HTMLElementEventMap, (event: never) => void]>

  constructor(
    private readonly canvas: HTMLCanvasElement,
    private readonly delegate: DragDelegate,
  ) {
    const down = (event: PointerEvent) => this.onPointerDown(event)
    const move = (event: PointerEvent) => this.onPointerMove(event)
    const up = (event: PointerEvent) => this.onPointerUp(event)

    this.handlers = [
      ['pointerdown', down as (event: never) => void],
      ['pointermove', move as (event: never) => void],
      ['pointerup', up as (event: never) => void],
      // A pointer that leaves the window mid-drag has to end the drag somewhere, or the piece
      // stays stuck to the cursor after the button is long released.
      ['pointercancel', up as (event: never) => void],
    ]

    for (const [name, handler] of this.handlers) {
      this.canvas.addEventListener(name, handler as EventListener)
    }
  }

  dispose(): void {
    for (const [name, handler] of this.handlers) {
      this.canvas.removeEventListener(name, handler as EventListener)
    }
  }

  // --- the gesture -----------------------------------------------------------

  /** The page holds the space bar: the camera takes the next gesture wherever it lands. */
  wantCamera(wanted: boolean): void {
    this.cameraWanted = wanted

    if (this.pressed === null) {
      this.delegate.setCursor(wanted ? 'move' : '')
    }
  }

  /** Abandon a drag in progress and put the piece back. Escape, and losing the window. */
  cancel(): void {
    if (this.pressed === null) {
      return
    }

    const { item, dragging, pointerId } = this.pressed

    this.pressed = null
    this.delegate.setOrbitEnabled(true)
    this.delegate.setCursor('')

    if (this.canvas.hasPointerCapture(pointerId)) {
      this.canvas.releasePointerCapture(pointerId)
    }

    if (dragging && item !== undefined) {
      this.delegate.onCancel(item.id)
    }
  }

  private onPointerDown(event: PointerEvent): void {
    // Secondary buttons belong to the camera and to the browser's own menu.
    if (event.button !== 0) {
      return
    }

    /*
     * One pointer at a time.
     *
     * A second finger arriving during a pinch used to land on whatever was under it and
     * overwrite the drag: the sofa jumped to the second finger and the zoom died, because the
     * orbit controls had been switched off for a drag that was no longer the gesture anybody
     * was making.
     */
    if (!event.isPrimary) {
      return
    }

    // A press on an arrow or the ring is the gizmo's, and the piece under it must not also
    // start following the pointer — two things moving one sofa by different amounts.
    if (this.delegate.gizmoActive()) {
      return
    }

    // Space or Alt: the view, whatever is under the pointer. The orbit controls already have
    // this press; leaving them alone is the whole of letting them have it.
    if (this.cameraWanted || event.altKey) {
      return
    }

    const item = this.itemUnder(event)
    const point = item === undefined ? null : this.pointOnPlane(event, item.position_y_mm)

    this.pressed = {
      pointerId: event.pointerId,
      item,
      screenX: event.clientX,
      screenY: event.clientY,
      offsetX: point === null || item === undefined ? 0 : item.position_x_mm - toMm(point.x),
      offsetZ: point === null || item === undefined ? 0 : item.position_z_mm - toMm(point.z),
      startX: item?.position_x_mm ?? 0,
      startZ: item?.position_z_mm ?? 0,
      dragging: false,
    }

    /*
     * The camera is held back only while a press sits on a piece that could move. A press on
     * the floor, or on a locked piece, is a gesture the camera is welcome to — and it will be,
     * unless the release turns out to have been a click.
     */
    if (item !== undefined && !item.locked && point !== null) {
      this.delegate.setOrbitEnabled(false)
      this.canvas.setPointerCapture(event.pointerId)
    }
  }

  /** What the resting pointer was last over, so hover is reported on change only. */
  private hovered: string | null = null

  private onPointerMove(event: PointerEvent): void {
    if (this.pressed === null) {
      // A resting pointer: say what it is over, once per change. Not while the gizmo has the
      // gesture — its handles are over the piece and would flicker the hover on and off.
      const over = this.delegate.gizmoActive() ? this.hovered : (this.itemUnder(event)?.id ?? null)

      if (over !== this.hovered) {
        this.hovered = over
        this.delegate.onHover(over)
        this.delegate.setCursor(this.cursorFor(over))
      }

      return
    }

    if (!event.isPrimary || event.pointerId !== this.pressed.pointerId) {
      return
    }

    const { item } = this.pressed

    if (item === undefined || item.locked) {
      return
    }

    /*
     * Still inside the dead zone: nothing has been dragged yet. Reported in screen pixels,
     * because the question is whether the hand meant to move something, and hands do not know
     * how far the camera is from the sofa.
     */
    if (!this.pressed.dragging) {
      const travelled = Math.hypot(event.clientX - this.pressed.screenX, event.clientY - this.pressed.screenY)

      if (travelled < DRAG_THRESHOLD_PX) {
        return
      }

      this.pressed.dragging = true
      this.delegate.onSelect(item.id)
      this.delegate.setCursor('grabbing')
    }

    const point = this.pointOnPlane(event, item.position_y_mm)

    if (point === null) {
      return
    }

    const snapped = this.delegate.snap(item, {
      x: toMm(point.x) + this.pressed.offsetX,
      z: toMm(point.z) + this.pressed.offsetZ,
    })

    const at = { x: snapped.x, z: snapped.z, rotation: snapped.rotation }

    this.delegate.onPreview(item.id, at, this.delegate.stateAt(item, at), snapped.guides)
  }

  private onPointerUp(event: PointerEvent): void {
    if (this.pressed === null || event.pointerId !== this.pressed.pointerId) {
      return
    }

    const { item, dragging, offsetX, offsetZ } = this.pressed

    this.pressed = null

    this.delegate.setOrbitEnabled(true)
    this.delegate.setCursor(this.cursorFor(this.hovered))

    if (this.canvas.hasPointerCapture(event.pointerId)) {
      this.canvas.releasePointerCapture(event.pointerId)
    }

    /*
     * A click, not a drag — so now it selects, and only now.
     *
     * Deciding this on the way down meant that turning the camera by dragging from an empty
     * patch of floor cleared the selection and tore down the gizmo before the first frame of
     * the turn. The selection is what the customer is working on; a gesture aimed at the view
     * has no business taking it away.
     */
    if (!dragging) {
      this.delegate.onSelect(item?.id ?? null)

      if (item !== undefined) {
        this.delegate.onCancel(item.id)
      }

      return
    }

    if (item === undefined) {
      return
    }

    const point = this.pointOnPlane(event, item.position_y_mm)

    if (point === null) {
      this.delegate.onCancel(item.id)

      return
    }

    // The same offset the grab was taken with. Dropping it here would move the piece by
    // however far from its centre it was picked up, on release, after the drag looked right.
    const snapped = this.delegate.snap(item, {
      x: toMm(point.x) + offsetX,
      z: toMm(point.z) + offsetZ,
    })

    this.delegate.onCommit(item.id, { x: snapped.x, z: snapped.z, rotation: snapped.rotation })
  }

  /**
   * What the pointer says it can do here.
   *
   * An open hand over something that can be picked up, a closed one while it is being carried,
   * and the barred circle over a piece somebody has locked — which until now said nothing at
   * all, so a locked sofa looked exactly like a draggable one that had stopped working.
   */
  private cursorFor(id: string | null): string {
    if (this.cameraWanted) {
      return 'move'
    }

    if (id === null) {
      return ''
    }

    return this.delegate.items().find(item => item.id === id)?.locked === true ? 'not-allowed' : 'grab'
  }

  // --- internals -------------------------------------------------------------

  private itemUnder(event: PointerEvent): LayoutItem | undefined {
    this.castFrom(event)

    // Recursive: the hit is on the box inside the group, and the id is on both.
    const hits = this.raycaster.intersectObjects(this.delegate.pickable(), true)

    for (const hit of hits) {
      const id = this.idOf(hit.object)

      if (id !== null) {
        return this.delegate.items().find(item => item.id === id)
      }
    }

    return undefined
  }

  private idOf(object: import('three').Object3D | null): string | null {
    let current = object

    while (current !== null) {
      const id: unknown = current.userData.itemId

      if (typeof id === 'string') {
        return id
      }

      current = current.parent
    }

    return null
  }

  private pointOnPlane(event: PointerEvent, heightMm: number): Vector3 | null {
    this.castFrom(event)

    this.plane.constant = -toUnits(heightMm)

    return this.raycaster.ray.intersectPlane(this.plane, this.hit)
  }

  private castFrom(event: PointerEvent): void {
    const rect = this.canvas.getBoundingClientRect()

    // Normalised device coordinates: -1 to 1 across the canvas, with y up. Taken from the
    // canvas's own box rather than the window, or every drag is offset by whatever is above
    // the canvas on the page.
    this.pointer.set(
      ((event.clientX - rect.left) / rect.width) * 2 - 1,
      -((event.clientY - rect.top) / rect.height) * 2 + 1,
    )

    this.raycaster.setFromCamera(this.pointer, this.delegate.camera())
  }
}
