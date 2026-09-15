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
  /** The handle moved the attached group. */
  onChange: () => void
  /** The handle was released. */
  onCommit: () => void
  /** Something on screen changed and the scene should draw a frame. */
  invalidate: () => void
}

/**
 * The move and turn handles on the selected piece.
 *
 * The storyboard's panel 5: arrows to slide a piece along the floor, a ring to turn it, the
 * way every planner anyone has used does it. The first editor had a drag and four buttons,
 * and the product owner's verdict was that it could not "turn or place like professional 3D".
 *
 * Built on Three.js's own TransformControls rather than drawn by hand: the picking, the
 * screen-space sizing, the hover highlight and the touch handling are a few thousand lines
 * that already exist and are already right.
 *
 * Two of its three axes are switched off. Furniture slides along the floor and turns about
 * the vertical, and a handle that could lift a sofa into the air or tip it onto its side is a
 * handle somebody will pull by accident.
 *
 * Turning snaps to fifteen degrees. Furniture in a room is square to the walls almost always,
 * and a sofa at 88° looks like a mistake in every render made from it; Shift frees the turn
 * for the customer who wants exactly 37.
 */
export class GizmoController {
  /** The step the turn handle snaps to, in degrees. */
  static readonly ROTATION_STEP_DEG = 15

  private readonly controls: TransformControls

  private readonly helper: Object3D

  private mode: GizmoMode = 'translate'

  constructor(
    canvas: HTMLCanvasElement,
    private readonly scene: Scene,
    private readonly delegate: GizmoDelegate,
  ) {
    this.controls = new TransformControls(delegate.camera(), canvas)
    this.controls.setSpace('world')
    this.controls.setSize(0.85)

    // The visible handles. TransformControls itself is no longer an Object3D; what goes in
    // the scene is this, and it must never be in the furniture group the drag raycasts.
    this.helper = this.controls.getHelper()
    this.helper.name = 'gizmo'
    this.scene.add(this.helper)

    this.controls.addEventListener('dragging-changed', (event) => {
      const dragging = Boolean((event as { value?: boolean }).value)

      // While a handle is held the camera must not also be orbiting.
      this.delegate.setOrbitEnabled(!dragging)

      if (!dragging) {
        this.delegate.onCommit()
      }
    })

    this.controls.addEventListener('objectChange', () => this.delegate.onChange())
    this.controls.addEventListener('change', () => this.delegate.invalidate())

    this.applyMode()
    this.setFreeRotation(false)
  }

  /** Puts the handles on a piece, or takes them off. */
  attach(group: Group | null): void {
    if (group === null) {
      this.controls.detach()
    }
    else {
      this.controls.attach(group)
    }

    this.delegate.invalidate()
  }

  attached(): Object3D | null {
    return this.controls.object ?? null
  }

  setMode(mode: GizmoMode): void {
    if (this.mode === mode) {
      return
    }

    this.mode = mode
    this.applyMode()
    this.delegate.invalidate()
  }

  getMode(): GizmoMode {
    return this.mode
  }

  /** Free rotation while Shift is held; fifteen-degree steps otherwise. */
  setFreeRotation(free: boolean): void {
    this.controls.rotationSnap = free ? null : (GizmoController.ROTATION_STEP_DEG * Math.PI) / 180
  }

  /**
   * Whether the pointer is on a handle, or holding one.
   *
   * The drag controller asks before starting its own drag: a press on the arrow is a press
   * on the handle, and the piece underneath it must not also start following the pointer.
   */
  isActive(): boolean {
    return this.controls.dragging || this.controls.axis !== null
  }

  /** The camera changed — the plan view swaps to an orthographic one — and the handles follow. */
  syncCamera(): void {
    this.controls.camera = this.delegate.camera()
    this.delegate.invalidate()
  }

  dispose(): void {
    this.controls.detach()
    this.scene.remove(this.helper)
    this.controls.dispose()
  }

  // --- internals -------------------------------------------------------------

  private applyMode(): void {
    this.controls.setMode(this.mode)

    if (this.mode === 'translate') {
      // Along the floor only.
      this.controls.showX = true
      this.controls.showZ = true
      this.controls.showY = false
    }
    else {
      // About the vertical only.
      this.controls.showX = false
      this.controls.showZ = false
      this.controls.showY = true
    }
  }
}
