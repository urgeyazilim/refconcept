import type { Camera, Group, Object3D, Scene } from 'three'
import { TransformControls } from 'three/examples/jsm/controls/TransformControls.js'

export type GizmoMode = 'translate' | 'rotate'

/**
 * What the gizmo needs from the editor, and what it tells the editor back.
 *
 * The gizmo moves the attached group directly — that is how TransformControls works — and the
 * editor then reads where the group ended up, holds it inside the walls and off the other
 * pieces, and writes the held position back. The gizmo never knows about millimetres.
 */
export interface GizmoDelegate {
  camera: () => Camera
  setOrbitEnabled: (enabled: boolean) => void
  /** A handle moved the attached group. */
  onChange: () => void
  /** The handle was released. */
  onCommit: () => void
  /** Something on screen changed and the scene should draw a frame. */
  invalidate: () => void
}

/**
 * The move and turn handles on the selected piece, both at once.
 *
 * The storyboard's panel 5: arrows to slide a piece along the floor and a ring to turn it,
 * the way every planner anyone has used does it. The first version showed one or the other
 * behind a toggle, and the product owner's first words on seeing the arrows were "döndürme
 * yok" — a handle behind a button is a handle that is not there.
 *
 * Built on two of Three.js's own TransformControls rather than drawn by hand: one in
 * translate mode with the vertical axis off, one in rotate mode with only the vertical ring.
 * The picking, the screen-space sizing, the hover and the touch handling are a few thousand
 * lines that already exist and are already right. While one is being dragged the other is
 * switched off, so the ring cannot be caught mid-slide.
 *
 * Turning snaps to fifteen degrees. Furniture in a room is square to the walls almost always,
 * and a sofa at 88° looks like a mistake in every render made from it; Shift frees the turn
 * for the customer who wants exactly 37.
 */
export class GizmoController {
  /** The step the turn handle snaps to, in degrees. */
  static readonly ROTATION_STEP_DEG = 15

  private readonly mover: TransformControls

  private readonly turner: TransformControls

  private readonly helpers: Object3D[]

  constructor(
    canvas: HTMLCanvasElement,
    private readonly scene: Scene,
    private readonly delegate: GizmoDelegate,
  ) {
    /*
     * Handles sized for whatever is pointing at them.
     *
     * 0.8 and 1.05 are comfortable for a mouse, which lands on a pixel. A fingertip covers
     * about nine millimetres of glass, so on a phone the arrows sat inside the contact patch
     * with the sofa and the ring beyond it — tapping either did whichever the raycast reached
     * first. Half again as large on a coarse pointer, which is what every touch device
     * reports and no mouse does.
     */
    const coarse = typeof matchMedia === 'function' && matchMedia('(pointer: coarse)').matches
    const scale = coarse ? 1.5 : 1

    this.mover = new TransformControls(delegate.camera(), canvas)
    this.mover.setMode('translate')
    this.mover.setSpace('world')
    this.mover.setSize(0.8 * scale)
    // Along the floor only. A handle that could lift a sofa into the air is one somebody
    // pulls by accident.
    this.mover.showY = false

    this.turner = new TransformControls(delegate.camera(), canvas)
    this.turner.setMode('rotate')
    this.turner.setSpace('world')
    this.turner.setSize(1.05 * scale)
    // About the vertical only.
    this.turner.showX = false
    this.turner.showZ = false

    // The visible handles. TransformControls itself is no longer an Object3D; what goes in
    // the scene is this, and it must never be in the furniture group the drag raycasts.
    this.helpers = [this.mover.getHelper(), this.turner.getHelper()]
    this.helpers[0]!.name = 'gizmo-move'
    this.helpers[1]!.name = 'gizmo-turn'
    this.scene.add(...this.helpers)

    for (const [controls, other] of [[this.mover, this.turner], [this.turner, this.mover]] as const) {
      controls.addEventListener('dragging-changed', (event) => {
        const dragging = Boolean((event as { value?: boolean }).value)

        // While a handle is held the camera must not also be orbiting, and the other set of
        // handles must not be catchable.
        this.delegate.setOrbitEnabled(!dragging)
        other.enabled = !dragging

        if (!dragging) {
          this.delegate.onCommit()
        }
      })

      controls.addEventListener('objectChange', () => this.delegate.onChange())
      controls.addEventListener('change', () => this.delegate.invalidate())
    }

    this.setFreeRotation(false)
  }

  /** Puts the handles on a piece, or takes them off. */
  attach(group: Group | null): void {
    for (const controls of [this.mover, this.turner]) {
      if (group === null) {
        controls.detach()
      }
      else {
        controls.attach(group)
      }
    }

    this.delegate.invalidate()
  }

  attached(): Object3D | null {
    return this.mover.object ?? null
  }

  /** Which handle is being dragged right now, if any. */
  activeMode(): GizmoMode | null {
    if (this.mover.dragging) {
      return 'translate'
    }

    if (this.turner.dragging) {
      return 'rotate'
    }

    return null
  }

  /** Free rotation while Shift is held; fifteen-degree steps otherwise. */
  setFreeRotation(free: boolean): void {
    this.turner.rotationSnap = free ? null : (GizmoController.ROTATION_STEP_DEG * Math.PI) / 180
  }

  /**
   * Whether the pointer is on a handle, or holding one.
   *
   * The drag controller asks before starting its own drag: a press on the arrow is a press
   * on the handle, and the piece underneath it must not also start following the pointer.
   */
  isActive(): boolean {
    return this.mover.dragging || this.turner.dragging || this.mover.axis !== null || this.turner.axis !== null
  }

  /** The camera changed — the plan view swaps to an orthographic one — and the handles follow. */
  syncCamera(): void {
    this.mover.camera = this.delegate.camera()
    this.turner.camera = this.delegate.camera()
    this.delegate.invalidate()
  }

  dispose(): void {
    this.mover.detach()
    this.turner.detach()
    this.scene.remove(...this.helpers)
    this.mover.dispose()
    this.turner.dispose()
  }
}
