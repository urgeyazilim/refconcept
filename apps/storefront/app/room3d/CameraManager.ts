import type { Object3D } from 'three'
import { Box3, OrthographicCamera, PerspectiveCamera, Vector3 } from 'three'
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js'

import { type RoomGeometry, type ViewMode, toUnits } from './types'

/**
 * The three ways of looking at a room, and the controls that move between them.
 *
 * Perspective for judging how a room feels, orthographic from above for judging where things
 * are. They are genuinely different questions: a perspective view makes a sofa near the
 * camera look larger than a wardrobe at the back, which is honest about the experience and
 * useless for deciding whether the walkway is wide enough. The plan view has no perspective
 * at all, so two gaps that measure the same look the same.
 *
 * "Inside" is the perspective camera moved to eye height in a corner — the view somebody
 * actually has walking in, which is the one that tells you a room is cramped before any
 * measurement does.
 */
export class CameraManager {
  private readonly perspective: PerspectiveCamera

  private readonly orthographic: OrthographicCamera

  private readonly controls: OrbitControls

  /** Read by the scene to decide which walls are in the way. */
  mode: ViewMode = 'perspective'

  /** The room's middle, which every view looks at and orbits around. */
  private target = new Vector3(0, 0, 0)

  private geometry: RoomGeometry | null = null

  constructor(canvas: HTMLCanvasElement) {
    const aspect = canvas.clientWidth === 0 ? 1 : canvas.clientWidth / canvas.clientHeight

    /*
     * Fifty degrees, not the seventy-five Three.js defaults to.
     *
     * A wide lens inside a small room exaggerates its depth enormously — the far wall races
     * away and a 4-metre living room reads as a hall. Fifty is close to what a phone camera
     * sees, which is what the customer's own photograph was taken with, so the 3D room and
     * the photograph beside it feel like the same place.
     */
    this.perspective = new PerspectiveCamera(50, aspect, 0.05, 200)

    // Extents are set properly the moment a room arrives; these only have to be non-zero.
    this.orthographic = new OrthographicCamera(-5, 5, 5, -5, 0.05, 200)

    this.controls = new OrbitControls(this.perspective, canvas)
    this.controls.enableDamping = true
    this.controls.dampingFactor = 0.08

    // Stopped just above the floor. Orbiting under the room shows its underside and there is
    // nothing down there anybody wants to see.
    this.controls.maxPolarAngle = Math.PI / 2 - 0.05

    this.controls.minDistance = 0.6
    this.controls.maxDistance = 40

    /*
     * The wheel zooms towards whatever is under the pointer, not towards the middle.
     *
     * Three.js defaults to the orbit target, which is the centre of the room and stays there
     * unless somebody pans. So leaning in to look at the corner where the bookcase goes
     * pushed the corner off the screen and filled the view with the middle of the floor; the
     * only way in was zoom, pan, zoom, pan. Every planner, map and drawing tool zooms to the
     * cursor, and hands already expect it.
     */
    this.controls.zoomToCursor = true

    /*
     * A gentler wheel.
     *
     * One notch is a factor of 0.95 by default, and a mouse that sends several notches per
     * flick crosses the whole 0.6-to-40-metre range in one gesture. At 0.6 a flick is about
     * a third of the distance, which is a movement rather than a jump.
     */
    this.controls.zoomSpeed = 0.6

    this.listenForWalking(canvas)
  }

  get active(): PerspectiveCamera | OrthographicCamera {
    return this.mode === 'top' ? this.orthographic : this.perspective
  }

  /**
   * Advances the controls' damping.
   *
   * Returns whether anything moved, so the render loop can stay idle when nothing has. A
   * planner sits still most of the time it is open.
   */
  update(): boolean {
    if (this.flight !== null) {
      return this.fly()
    }

    if (this.mode === 'inside') {
      return this.walkUpdate()
    }

    return this.controls.update()
  }

  /**
   * In or out by one step, for a button or a keyboard.
   *
   * Zoom was the wheel and the pinch and nothing else — so a laptop trackpad with no
   * horizontal-scroll convention, a stylus, and every touch device in `İçeriden` mode had no
   * way in or out at all. A step is the same fifteen percent a wheel notch gives, applied to
   * the distance from the target in perspective and to the frustum in plan.
   */
  zoomBy(factor: number): void {
    if (this.mode === 'top') {
      // An orthographic camera has no distance to the room; it has a window onto it.
      this.orthographic.zoom = Math.max(0.2, Math.min(12, this.orthographic.zoom * factor))
      this.orthographic.updateProjectionMatrix()
      this.controls.update()

      return
    }

    const target = this.controls.target
    const offset = this.perspective.position.clone().sub(target)
    const distance = offset.length()

    if (distance === 0) {
      return
    }

    const wanted = Math.max(this.controls.minDistance, Math.min(this.controls.maxDistance, distance / factor))

    this.perspective.position.copy(target).add(offset.multiplyScalar(wanted / distance))
    this.controls.update()
  }

  // --- flights -------------------------------------------------------------------

  /**
   * A camera move in progress: from where it was to where it is going, eased.
   *
   * A view that cuts from one angle to another loses the customer for a moment — which wall
   * is which, where did the sofa go. A short flight keeps the room continuous under them.
   */
  private flight: {
    fromPosition: Vector3
    toPosition: Vector3
    fromTarget: Vector3
    toTarget: Vector3
    startedAt: number
    ms: number
  } | null = null

  /** Flies the perspective camera to a position and a point to look at. */
  flyTo(position: Vector3, target: Vector3, ms = 650): void {
    this.flight = {
      fromPosition: this.perspective.position.clone(),
      toPosition: position.clone(),
      fromTarget: this.controls.target.clone(),
      toTarget: target.clone(),
      startedAt: performance.now(),
      ms,
    }
  }

  /**
   * Flies to look at a point in the room from where the camera is now, closer.
   *
   * A double-click on a sofa: the camera keeps its direction and comes in to a distance the
   * piece fills, so the customer is looking at the thing they clicked rather than at a room
   * with that thing somewhere in it.
   */
  focusOn(point: Vector3, distance: number): void {
    /*
     * In the plan view there is no direction to come in from — the camera is straight above
     * and the way to look closer is to narrow the window and slide it over the piece.
     */
    if (this.mode === 'top') {
      this.controls.target.set(point.x, this.controls.target.y, point.z)
      this.orthographic.position.set(point.x, this.orthographic.position.y, point.z)
      this.orthographic.zoom = Math.max(0.2, Math.min(12, 3 / Math.max(distance, 0.5)))
      this.orthographic.updateProjectionMatrix()
      this.controls.update()

      return
    }

    /*
     * Standing in the room, "look closer" means turning to face the piece rather than flying
     * at it. Until now this returned silently outside the perspective view, so the button
     * stayed enabled, did nothing, and gave no reason.
     */
    if (this.mode === 'inside') {
      this.perspective.lookAt(point)
      this.walk.yaw = Math.atan2(
        this.perspective.position.x - point.x,
        this.perspective.position.z - point.z,
      ) + Math.PI
      this.walk.pitch = Math.asin(
        Math.max(-1, Math.min(1, (point.y - this.perspective.position.y) / Math.max(this.perspective.position.distanceTo(point), 0.001))),
      )

      return
    }

    const direction = this.perspective.position.clone().sub(this.controls.target).normalize()

    if (direction.lengthSq() === 0) {
      direction.set(0.6, 0.5, 0.6).normalize()
    }

    const position = point.clone().add(direction.multiplyScalar(Math.max(distance, this.controls.minDistance * 2)))

    this.flyTo(position, point)
  }

  private fly(): boolean {
    const flight = this.flight

    if (flight === null) {
      return false
    }

    const t = Math.min(1, (performance.now() - flight.startedAt) / flight.ms)
    // Ease out: fast to leave, slow to arrive, which is how a camera on a crane moves.
    const eased = 1 - (1 - t) ** 3

    this.perspective.position.lerpVectors(flight.fromPosition, flight.toPosition, eased)
    this.controls.target.lerpVectors(flight.fromTarget, flight.toTarget, eased)
    this.perspective.lookAt(this.controls.target)

    if (t >= 1) {
      this.flight = null
      this.controls.update()
    }

    return true
  }

  /**
   * Stops the camera moving while somebody is dragging furniture.
   *
   * Both gestures are a pointer dragged across the canvas, and without this a sofa pulled
   * across the room takes the room with it. Inside the room the same flag stops the
   * look-around, for the same reason.
   */
  setOrbitEnabled(enabled: boolean): void {
    this.orbitWanted = enabled
    this.controls.enabled = enabled && this.mode !== 'inside'
  }

  setMode(mode: ViewMode): void {
    const previous = this.mode
    this.mode = mode

    if (this.geometry !== null) {
      // Between two perspective views the camera flies; to or from the plan it cuts, because
      // an orthographic camera has nowhere to fly from.
      const flies = previous === 'perspective' && mode === 'perspective'
      const before = this.perspective.position.clone()
      const beforeTarget = this.controls.target.clone()

      this.frame(this.geometry)

      if (flies) {
        const destination = this.perspective.position.clone()
        const destinationTarget = this.controls.target.clone()

        this.perspective.position.copy(before)
        this.controls.target.copy(beforeTarget)
        this.flyTo(destination, destinationTarget)
      }
      else {
        this.flight = null
      }
    }

    // Inside, the orbit controls are off and the walk takes the pointer and the keys.
    this.controls.enabled = this.orbitWanted && mode !== 'inside'
    this.walk.keys.clear()
    this.walk.looking = null
  }

  // --- walking about -----------------------------------------------------------

  /**
   * The inside view is walked, not orbited.
   *
   * An orbit from inside swings the camera through the wall behind you and the room turns
   * inside out; the first version hid that with a distant target and it still never felt
   * like standing in the room. Now the camera stays at eye height, a drag turns the head,
   * and W A S D walk — held inside the walls, because a customer can no more walk through
   * one than their sofa can.
   */
  private readonly walk = {
    yaw: 0,
    pitch: 0,
    keys: new Set<string>(),
    looking: null as { x: number, y: number, yaw: number, pitch: number } | null,
    last: 0,
  }

  /** Whether the editor wants the camera to answer the pointer at all; false during a drag. */
  private orbitWanted = true

  /** Metres per second, on the flat. A stroll, not a sprint. */
  private static readonly WALK_SPEED = 1.4

  /** How far from a wall the walk stops. */
  private static readonly WALL_MARGIN = 0.3

  private readonly walkHandlers: Array<[EventTarget, string, EventListener]> = []

  private listenForWalking(canvas: HTMLCanvasElement): void {
    const on = (target: EventTarget, name: string, handler: EventListener): void => {
      target.addEventListener(name, handler)
      this.walkHandlers.push([target, name, handler])
    }

    on(canvas, 'pointerdown', ((event: PointerEvent) => {
      if (this.mode !== 'inside' || !this.orbitWanted || event.button !== 0) {
        return
      }

      this.walk.looking = { x: event.clientX, y: event.clientY, yaw: this.walk.yaw, pitch: this.walk.pitch }
      canvas.setPointerCapture(event.pointerId)
    }) as EventListener)

    on(canvas, 'pointermove', ((event: PointerEvent) => {
      const looking = this.walk.looking

      if (looking === null || !this.orbitWanted) {
        return
      }

      // Mouse-look: drag left, look left. Pitch is kept off the poles.
      this.walk.yaw = looking.yaw - (event.clientX - looking.x) * 0.004
      this.walk.pitch = Math.max(-1.2, Math.min(1.2, looking.pitch - (event.clientY - looking.y) * 0.004))
      this.applyLook()
    }) as EventListener)

    const stop = ((event: PointerEvent) => {
      this.walk.looking = null

      if (canvas.hasPointerCapture(event.pointerId)) {
        canvas.releasePointerCapture(event.pointerId)
      }
    }) as EventListener

    on(canvas, 'pointerup', stop)
    on(canvas, 'pointercancel', stop)

    const typing = (event: KeyboardEvent): boolean => {
      const target = event.target as HTMLElement | null

      return target !== null && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable)
    }

    on(window, 'keydown', ((event: KeyboardEvent) => {
      if (this.mode !== 'inside' || typing(event)) {
        return
      }

      const key = event.key.toLowerCase()

      if (['w', 'a', 's', 'd'].includes(key)) {
        this.walk.keys.add(key)
        event.preventDefault()
      }
    }) as EventListener)

    on(window, 'keyup', ((event: KeyboardEvent) => {
      this.walk.keys.delete(event.key.toLowerCase())
    }) as EventListener)
  }

  /** Turns the camera to the walk's yaw and pitch. */
  private applyLook(): void {
    this.perspective.rotation.set(this.walk.pitch, this.walk.yaw, 0, 'YXZ')
  }

  /** Moves the camera for the keys held, and says whether anything changed. */
  private walkUpdate(): boolean {
    const now = performance.now()
    const dt = this.walk.last === 0 ? 0 : Math.min(0.05, (now - this.walk.last) / 1000)

    this.walk.last = now

    const keys = this.walk.keys
    const ahead = (keys.has('w') ? 1 : 0) - (keys.has('s') ? 1 : 0)
    const side = (keys.has('d') ? 1 : 0) - (keys.has('a') ? 1 : 0)

    if ((ahead === 0 && side === 0) || this.geometry === null || dt === 0) {
      return this.walk.looking !== null
    }

    // Forward on the flat is where the head is turned, ignoring how far up or down it looks.
    const forward = { x: -Math.sin(this.walk.yaw), z: -Math.cos(this.walk.yaw) }
    const right = { x: Math.cos(this.walk.yaw), z: -Math.sin(this.walk.yaw) }

    const step = CameraManager.WALK_SPEED * dt
    const position = this.perspective.position

    position.x += (forward.x * ahead + right.x * side) * step
    position.z += (forward.z * ahead + right.z * side) * step

    const margin = CameraManager.WALL_MARGIN

    position.x = Math.max(margin, Math.min(toUnits(this.geometry.width_mm) - margin, position.x))
    position.z = Math.max(margin, Math.min(toUnits(this.geometry.length_mm) - margin, position.z))

    return true
  }

  /**
   * Points whichever camera is active at the whole room.
   *
   * Called when a room arrives and whenever the view changes, because a camera framed for a
   * 3-metre bedroom shows a corner of a 6-metre living room and reads as a broken scene
   * rather than a camera that needs moving.
   */
  /** Flies back to the whole room, from wherever the customer has orbited to. */
  reframe(): void {
    if (this.geometry === null) {
      return
    }

    /*
     * Only the perspective view flies.
     *
     * From above there is nowhere to fly from — the camera is already straight down and
     * framing the room is the window going back to the size of the floor. Standing inside it,
     * a flight would walk the customer through their own furniture; the honest answer is to
     * put them back where the view starts, in the corner, facing in.
     *
     * The button used to be disabled in both, which read as a view that could not be
     * recovered once it had been lost.
     */
    if (this.mode !== 'perspective') {
      this.frame(this.geometry)
      this.controls.update()

      return
    }

    const before = this.perspective.position.clone()
    const beforeTarget = this.controls.target.clone()

    this.frame(this.geometry)

    const destination = this.perspective.position.clone()
    const destinationTarget = this.controls.target.clone()

    this.perspective.position.copy(before)
    this.controls.target.copy(beforeTarget)
    this.flyTo(destination, destinationTarget)
  }

  /**
   * Puts the camera round whatever this is, rather than round a room.
   *
   * The reconstruction is a cloud with no walls and no floor to stand on, so the room's own
   * framing has nothing to work from. Its bounding sphere does.
   */
  frameObject(object: Object3D): void {
    const box = new Box3().setFromObject(object)
    const centre = box.getCenter(new Vector3())
    const size = box.getSize(new Vector3())
    const reach = Math.max(size.x, size.y, size.z) * 1.4

    this.target.copy(centre)
    this.perspective.position.set(centre.x + reach, centre.y + reach * 0.6, centre.z + reach)
    this.perspective.lookAt(centre)

    this.controls.target.copy(this.target)
    this.controls.update()
  }

  frame(geometry: RoomGeometry): void {
    this.geometry = geometry

    const width = toUnits(geometry.width_mm)
    const length = toUnits(geometry.length_mm)
    const height = toUnits(geometry.height_mm)

    this.target.set(width / 2, height / 3, length / 2)

    switch (this.mode) {
      case 'top':
        this.frameTop(width, length, height)
        break

      case 'inside':
        this.frameInside(width, length, height)
        break

      default:
        this.framePerspective(width, length, height)
    }

    this.controls.target.copy(this.target)
    this.controls.update()
  }

  setAspect(aspect: number): void {
    this.perspective.aspect = aspect
    this.perspective.updateProjectionMatrix()

    if (this.geometry !== null && this.mode === 'top') {
      this.frameTop(
        toUnits(this.geometry.width_mm),
        toUnits(this.geometry.length_mm),
        toUnits(this.geometry.height_mm),
      )
    }
  }

  dispose(): void {
    for (const [target, name, handler] of this.walkHandlers) {
      target.removeEventListener(name, handler)
    }

    this.controls.dispose()
  }

  // --- framings --------------------------------------------------------------

  private framePerspective(width: number, length: number, height: number): void {
    /*
     * Outside one corner, above head height, looking down into the room.
     *
     * The distance is derived from the room rather than fixed, so a studio and a salon are
     * both filled rather than one being a speck and the other cropped.
     */
    const reach = Math.max(width, length) * 1.5
    const from = new Vector3(width / 2 + reach * 0.7, height * 1.6, length / 2 + reach * 0.8)

    /*
     * The same viewpoint, at whatever distance shows the whole room in this canvas. The
     * distance used to be fixed by the room alone, which filled a 4:3 box and cut the near
     * edge of the floor in a wide one — the plan workspace is as tall as the window and
     * rarely 4:3. The room's bounding sphere is fitted into the narrower of the two fields
     * of view, so a wide box shows the room with air at the sides and a tall one with air
     * above, and the floor is in the picture either way.
     */
    const radius = Math.sqrt(width * width + length * length + height * height) / 2
    const vertical = (this.perspective.fov * Math.PI) / 360
    const horizontal = Math.atan(Math.tan(vertical) * Math.max(this.perspective.aspect, 0.1))
    const distance = (radius * 0.95) / Math.sin(Math.min(vertical, horizontal))
    const direction = from.clone().sub(this.target).normalize()

    this.perspective.position.copy(this.target).addScaledVector(direction, Math.max(distance, radius * 1.2))

    this.controls.object = this.perspective
    this.controls.enableRotate = true
    this.controls.maxPolarAngle = Math.PI / 2 - 0.05
  }

  private frameTop(width: number, length: number, height: number): void {
    const aspect = this.perspective.aspect

    // A tenth of margin so the walls are not flush against the edge of the canvas, where
    // they are hard to distinguish from its border.
    const halfWidth = (width / 2) * 1.1
    const halfLength = (length / 2) * 1.1

    // Whichever dimension the canvas is tighter in decides the zoom; the other gets slack.
    const halfHeight = Math.max(halfLength, halfWidth / aspect)

    this.orthographic.left = -halfHeight * aspect
    this.orthographic.right = halfHeight * aspect
    this.orthographic.top = halfHeight
    this.orthographic.bottom = -halfHeight
    this.orthographic.updateProjectionMatrix()

    this.orthographic.position.set(width / 2, height * 4, length / 2)

    // Straight down. Without an explicit up vector pointing along -z the camera has no
    // defined orientation looking down the y axis and the plan arrives at a random rotation.
    this.orthographic.up.set(0, 0, -1)
    this.orthographic.lookAt(width / 2, 0, length / 2)

    this.target.set(width / 2, 0, length / 2)

    this.controls.object = this.orthographic

    /*
     * No orbiting from above, and no polar limit.
     *
     * OrbitControls keeps the camera on a sphere whose axis is the camera's own up vector,
     * and looking straight down needs that vector pointing along -z. In that frame the camera
     * sits at ninety degrees from the pole, which the usual limit clamps — so every update
     * quietly shoved the plan off to one side of the canvas, and it looked like the
     * framing arithmetic was wrong rather than the controls.
     *
     * A plan view has nothing to orbit to anyway: there is exactly one useful angle. Panning
     * and zooming stay.
     */
    this.controls.enableRotate = false
    this.controls.maxPolarAngle = Math.PI
  }

  private frameInside(width: number, length: number, height: number): void {
    /*
     * Standing just inside the room at eye height, looking across it.
     *
     * 1.6 m rather than the middle of the wall: the whole value of this view is that it is
     * the height a person's eyes are at, and a camera at 1.35 m makes every room look
     * taller and more generous than it will be.
     */
    const eye = Math.min(1.6, height * 0.7)

    this.perspective.position.set(width * 0.12, eye, length * 0.12)

    this.target.set(width * 0.8, eye * 0.9, length * 0.8)

    this.controls.object = this.perspective

    // Facing the far corner, level. From here the walk and the look-around take over.
    const dx = this.target.x - this.perspective.position.x
    const dz = this.target.z - this.perspective.position.z

    this.walk.yaw = Math.atan2(-dx, -dz)
    this.walk.pitch = -0.05
    this.walk.last = 0
    this.applyLook()
  }
}
