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
}

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

  /** The piece being dragged, the offset it was grabbed by, and where it started. */
  private dragging: {
    item: LayoutItem
    offsetX: number
    offsetZ: number
    startX: number
    startZ: number
    moved: boolean
  } | null = null

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

  private onPointerDown(event: PointerEvent): void {
    // Secondary buttons belong to the camera and to the browser's own menu.
    if (event.button !== 0) {
      return
    }

    // A press on an arrow or the ring is the gizmo's, and the piece under it must not also
    // start following the pointer — two things moving one sofa by different amounts.
    if (this.delegate.gizmoActive()) {
      return
    }

    const item = this.itemUnder(event)

    this.delegate.onSelect(item?.id ?? null)

    if (item === undefined || item.locked) {
      return
    }

    const point = this.pointOnPlane(event, item.position_y_mm)

    if (point === null) {
      return
    }

    this.dragging = {
      item,
      offsetX: item.position_x_mm - toMm(point.x),
      offsetZ: item.position_z_mm - toMm(point.z),
      startX: item.position_x_mm,
      startZ: item.position_z_mm,
      moved: false,
    }

    this.delegate.setOrbitEnabled(false)
    this.canvas.setPointerCapture(event.pointerId)
  }

  /** What the resting pointer was last over, so hover is reported on change only. */
  private hovered: string | null = null

  private onPointerMove(event: PointerEvent): void {
    if (this.dragging === null) {
      // A resting pointer: say what it is over, once per change. Not while the gizmo has the
      // gesture — its handles are over the piece and would flicker the hover on and off.
      const over = this.delegate.gizmoActive() ? this.hovered : (this.itemUnder(event)?.id ?? null)

      if (over !== this.hovered) {
        this.hovered = over
        this.delegate.onHover(over)
      }

      return
    }

    const point = this.pointOnPlane(event, this.dragging.item.position_y_mm)

    if (point === null) {
      return
    }

    const desired = {
      x: toMm(point.x) + this.dragging.offsetX,
      z: toMm(point.z) + this.dragging.offsetZ,
    }

    const snapped = this.delegate.snap(this.dragging.item, desired)

    this.dragging.moved = snapped.x !== this.dragging.startX || snapped.z !== this.dragging.startZ

    const at = { x: snapped.x, z: snapped.z, rotation: snapped.rotation }

    this.delegate.onPreview(
      this.dragging.item.id,
      at,
      this.delegate.stateAt(this.dragging.item, at),
      snapped.guides,
    )
  }

  private onPointerUp(event: PointerEvent): void {
    if (this.dragging === null) {
      return
    }

    const { item, moved, offsetX, offsetZ } = this.dragging

    this.dragging = null

    this.delegate.setOrbitEnabled(true)

    if (this.canvas.hasPointerCapture(event.pointerId)) {
      this.canvas.releasePointerCapture(event.pointerId)
    }

    const point = this.pointOnPlane(event, item.position_y_mm)

    if (point === null || !moved) {
      // A click rather than a drag. It selected something, which is what the customer meant,
      // and writing an identical position to the history would fill undo with nothing.
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
