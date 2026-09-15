import type { Object3D } from 'three';
import {
  ACESFilmicToneMapping,
  AmbientLight,
  BufferGeometry,
  Color,
  DirectionalLight,
  Group,
  Line,
  LineBasicMaterial,
  PCFSoftShadowMap,
  PMREMGenerator,
  Scene,
  SRGBColorSpace,
  Vector3,
  WebGLRenderer,
} from 'three'
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js'

import { CameraManager } from './CameraManager'
import type { CollisionState } from './CollisionEngine'
import { FurnitureBuilder } from './FurnitureBuilder'
import { RoomGeometryBuilder } from './RoomGeometryBuilder'
import type { SnapGuide } from './SnapEngine'
import { type LayoutItem, type RoomGeometry, type RoomOpening, type ViewMode, toUnits } from './types'

/**
 * Owns the canvas, the renderer and the render loop.
 *
 * One of these per editor. Everything else in this folder is a plain class that knows
 * nothing about Vue, and this is the seam: a component creates a SceneManager in `onMounted`,
 * calls `dispose` in `onBeforeUnmount`, and never touches Three.js itself. That is the whole
 * reason the 3D code does not live inside a `.vue` file — a scene that is built inside a
 * component's setup is a scene that is rebuilt on every reactive change nobody expected to
 * matter, and the symptom is a room that flickers and a fan that will not stop.
 *
 * The loop is driven by `requestAnimationFrame` but renders only when something has changed.
 * A furniture planner is static most of the time it is open; rendering sixty idle frames a
 * second on a laptop is a warm room and a flat battery for no picture anybody asked for.
 */
export class SceneManager {
  readonly scene = new Scene()

  readonly cameras: CameraManager

  private readonly renderer: WebGLRenderer

  private readonly rooms = new RoomGeometryBuilder()

  // Told to redraw when a product photograph finishes downloading: the loop only draws when
  // something has changed, and a texture arriving later is a change nothing else reports.
  private readonly furniture = new FurnitureBuilder(() => this.invalidate())

  /**
   * Every piece currently in the scene, by id.
   *
   * Kept as a map rather than read back out of the scene graph each time because a drag
   * touches one piece sixty times a second, and searching a graph by name to move something
   * is a search that gets slower as the room gets fuller.
   */
  private readonly pieces = new Map<string, Group>()

  /** Holds the furniture, so it can be raycast without the walls getting in the way. */
  private readonly layout = new Group()

  /** The alignment lines a snap draws, cleared and rebuilt whenever they change. */
  private readonly guides = new Group()

  private readonly guideMaterial = new LineBasicMaterial({ color: 0xb08f52, transparent: true, opacity: 0.8 })

  private room: Group | null = null

  /** Kept so occlusion can be recomputed without asking the room its size every frame. */
  private geometry: RoomGeometry | null = null

  private frame: number | null = null

  /** Set whenever something moved; cleared once a frame has been drawn. */
  private dirty = true

  /** Told after each drawn frame, so HTML overlays can follow the camera. */
  private frameCallback: (() => void) | null = null

  private readonly resize: () => void

  constructor(private readonly canvas: HTMLCanvasElement) {
    this.renderer = new WebGLRenderer({
      canvas,
      antialias: true,
      // The screenshot service reads pixels back out of this canvas to send to the renderer,
      // and a buffer the driver is free to discard after presenting comes back blank.
      preserveDrawingBuffer: true,
    })

    this.renderer.outputColorSpace = SRGBColorSpace

    // Filmic rather than linear: a white room under a single bright light clips to flat
    // white everywhere the light lands, and the corners a customer is trying to judge
    // disappear into it.
    this.renderer.toneMapping = ACESFilmicToneMapping
    this.renderer.toneMappingExposure = 1.05

    this.renderer.shadowMap.enabled = true
    this.renderer.shadowMap.type = PCFSoftShadowMap

    this.scene.background = new Color(0xeeece8)

    this.cameras = new CameraManager(canvas)

    this.layout.name = 'layout'
    this.guides.name = 'guides'
    this.scene.add(this.layout, this.guides)

    this.light()

    this.resize = () => this.handleResize()
    window.addEventListener('resize', this.resize)

    this.handleResize()
    this.start()
  }

  /**
   * Puts a room in the scene, replacing whatever was there.
   *
   * Replacing rather than adding, because a customer who corrects their measurements is not
   * asking for a second room beside the first — and a scene that accumulates them looks, for
   * the half second before anybody notices, like the walls have doubled.
   */
  setRoom(geometry: RoomGeometry, openings: RoomOpening[]): void {
    if (this.room !== null) {
      this.scene.remove(this.room)
      this.rooms.dispose(this.room)
    }

    this.geometry = geometry
    this.room = this.rooms.build(geometry, openings)
    this.scene.add(this.room)

    this.cameras.frame(geometry)
    this.invalidate()
  }

  /**
   * Puts the layout's furniture in the room.
   *
   * Reconciled rather than rebuilt: pieces already in the scene are moved and recoloured,
   * new ones are added, and only what has actually gone is thrown away. Rebuilding the lot on
   * every change would work and would also drop the selection outline, re-upload every
   * geometry to the card, and make the room blink each time somebody turns a chair.
   */
  setItems(items: LayoutItem[], states: Map<string, CollisionState>, selectedId: string | null): void {
    const seen = new Set<string>()

    for (const item of items) {
      seen.add(item.id)

      let group = this.pieces.get(item.id)

      if (group === undefined) {
        group = this.furniture.build(item)
        this.pieces.set(item.id, group)
        this.layout.add(group)
      }
      else {
        this.furniture.place(group, item)
      }

      this.furniture.paint(group, item, states.get(item.id) ?? 'ok', item.id === selectedId)
    }

    for (const [id, group] of this.pieces) {
      if (seen.has(id)) {
        continue
      }

      this.layout.remove(group)
      this.furniture.dispose(group)
      this.pieces.delete(id)
    }

    this.invalidate()
  }

  /**
   * Moves one piece to a position nothing has been saved at yet.
   *
   * The drag's hot path. It takes the item and a position rather than a changed item so the
   * caller does not have to clone its state sixty times a second to show a preview.
   */
  previewItem(item: LayoutItem, at: { x: number, z: number, rotation?: number }, state: CollisionState): void {
    const group = this.pieces.get(item.id)

    if (group === undefined) {
      return
    }

    this.furniture.place(group, item, at)
    this.furniture.paint(group, item, state, true)

    this.invalidate()
  }

  /** The alignment lines for the snap in progress, or none. */
  setGuides(guides: SnapGuide[]): void {
    for (const child of [...this.guides.children]) {
      this.guides.remove(child)

      if (child instanceof Line) {
        child.geometry.dispose()
      }
    }

    for (const guide of guides) {
      // Just off the floor. Exactly on it and the line and the floor fight over which is in
      // front, in stripes, differently on every machine.
      const y = 0.004

      const points = guide.axis === 'x'
        ? [new Vector3(toUnits(guide.at), y, toUnits(guide.from)), new Vector3(toUnits(guide.at), y, toUnits(guide.to))]
        : [new Vector3(toUnits(guide.from), y, toUnits(guide.at)), new Vector3(toUnits(guide.to), y, toUnits(guide.at))]

      this.guides.add(new Line(new BufferGeometry().setFromPoints(points), this.guideMaterial))
    }

    this.invalidate()
  }

  /** The furniture, for the drag's raycaster. Walls are deliberately not in here. */
  pickable(): Object3D[] {
    return this.layout.children
  }

  /** One piece's group, for the gizmo to take hold of. */
  pieceFor(id: string): Group | undefined {
    return this.pieces.get(id)
  }

  /**
   * Where a point on the floor plan is on the screen, in CSS pixels.
   *
   * Used to hang measurement labels over the scene as ordinary HTML rather than drawing text
   * into the canvas. Text in WebGL is either a texture that goes blurry the moment somebody
   * zooms, or a font atlas nobody wants to maintain for the sake of "185 cm".
   */
  projectToScreen(point: { x: number, y?: number, z: number }): { x: number, y: number } | null {
    const vector = new Vector3(toUnits(point.x), toUnits(point.y ?? 0), toUnits(point.z))

    vector.project(this.cameras.active)

    // Behind the camera. Projection wraps such points round to the far side of the screen,
    // where a label would sit over the scene pointing at nothing.
    if (vector.z > 1) {
      return null
    }

    const { clientWidth, clientHeight } = this.canvas

    return {
      x: ((vector.x + 1) / 2) * clientWidth,
      y: ((1 - vector.y) / 2) * clientHeight,
    }
  }

  /** Called after every drawn frame, so overlays can follow the camera. */
  onFrame(callback: () => void): void {
    this.frameCallback = callback
  }

  /** Which way the customer is looking at it. */
  setView(mode: ViewMode): void {
    this.cameras.setMode(mode)
    this.invalidate()
  }

  /** Anything that changes what the picture should look like calls this. */
  invalidate(): void {
    this.dirty = true
  }

  /**
   * Hides whatever is between the camera and the room.
   *
   * A room with six surfaces is a closed box, and a closed box seen from outside is a box:
   * the first version of this drew the ceiling and all four walls faithfully and the customer
   * saw a grey crate. What everybody actually means by a 3D room is the doll's-house view —
   * no ceiling, and the near walls taken away so you can see in.
   *
   * Decided per frame from where the camera is, rather than by a fixed rule about which two
   * walls to drop, so the room stays open as somebody orbits it. The test is simply whether
   * the camera is on the outside of a given wall; if it is, that wall is in the way.
   *
   * The ceiling comes back only from inside, where it is part of what the room feels like.
   */
  private updateOcclusion(): void {
    if (this.room === null || this.geometry === null) {
      return
    }

    const camera = this.cameras.active.position
    const inside = this.cameras.mode === 'inside'

    const width = toUnits(this.geometry.width_mm)
    const length = toUnits(this.geometry.length_mm)

    for (const child of this.room.children) {
      switch (child.name) {
        case 'ceiling':
          child.visible = inside
          break

        case 'wall-north':
          child.visible = inside || camera.z >= 0
          break

        case 'wall-south':
          child.visible = inside || camera.z <= length
          break

        case 'wall-west':
          child.visible = inside || camera.x >= 0
          break

        case 'wall-east':
          child.visible = inside || camera.x <= width
          break
      }
    }
  }

  /** The canvas as a PNG data URL, for the render pipeline and for thumbnails. */
  snapshot(): string {
    /*
     * Drawn synchronously first, unless the canvas is hidden.
     *
     * The loop may not have run since the last change, and a screenshot of a stale frame is
     * a screenshot of the wrong layout. But a hidden canvas — the plan view is showing — has
     * no size, and rendering into a zero-sized buffer replaces a good frame with nothing.
     * The buffer is preserved, so what is already in it is the last real view of the room.
     */
    if (this.canvas.clientWidth > 0 && this.canvas.clientHeight > 0) {
      this.render()
    }

    return this.canvas.toDataURL('image/png')
  }

  dispose(): void {
    if (this.frame !== null) {
      cancelAnimationFrame(this.frame)
      this.frame = null
    }

    window.removeEventListener('resize', this.resize)

    if (this.room !== null) {
      this.rooms.dispose(this.room)
      this.room = null
    }

    for (const group of this.pieces.values()) {
      this.furniture.dispose(group)
    }

    this.pieces.clear()
    this.setGuides([])

    this.furniture.disposeMaterials()
    this.guideMaterial.dispose()

    this.cameras.dispose()
    this.renderer.dispose()
  }

  // --- internals -------------------------------------------------------------

  private light(): void {
    /*
     * Two lights and no more.
     *
     * A soft ambient so nothing is ever pure black, and one directional standing in for
     * daylight through the window. A planner is not a rendering competition — its job is to
     * let somebody judge whether a sideboard fits beside a door, which needs legible edges
     * and honest shadows rather than a lighting rig.
     */
    this.scene.add(new AmbientLight(0xffffff, 0.55))

    /*
     * An environment map, for the light that comes from everywhere.
     *
     * Three's own room environment — a lit interior, baked to a reflection map. It is what
     * gives a glossy floor something to reflect and a matt wall a gradient instead of a flat
     * fill, and it does the job the ambient light used to do, but with direction. Without it
     * the room was lit like a diagram.
     */
    this.scene.environment = new PMREMGenerator(this.renderer).fromScene(new RoomEnvironment(), 0.04).texture
    this.scene.environmentIntensity = 0.85

    const sun = new DirectionalLight(0xfff4e6, 2.2)
    sun.position.set(toUnits(6000), toUnits(5000), toUnits(4000))
    sun.castShadow = true

    // Sized to a room rather than to the default unit cube, or the shadow map covers a
    // square metre somewhere near the origin and everything else is unshadowed.
    sun.shadow.camera.left = -12
    sun.shadow.camera.right = 12
    sun.shadow.camera.top = 12
    sun.shadow.camera.bottom = -12
    sun.shadow.mapSize.set(2048, 2048)

    // Without this, every flat surface shadows itself in stripes.
    sun.shadow.bias = -0.0005

    this.scene.add(sun)
  }

  private start(): void {
    const tick = () => {
      this.frame = requestAnimationFrame(tick)

      // The controls have their own damping and keep moving for a moment after a drag ends,
      // so they get asked whether they still need frames rather than being assumed idle.
      if (this.cameras.update()) {
        this.dirty = true
      }

      if (this.dirty) {
        // Before drawing, not after: a wall hidden a frame late is a wall the customer
        // sees flash across the room as they orbit past it.
        this.updateOcclusion()
        this.render()
      }
    }

    tick()
  }

  private render(): void {
    this.renderer.render(this.scene, this.cameras.active)
    this.dirty = false

    // After the frame rather than before: an overlay positioned from a camera that is about
    // to move is an overlay one frame behind the thing it labels, which reads as a label
    // sliding around loose over the scene.
    this.frameCallback?.()
  }

  private handleResize(): void {
    const { clientWidth, clientHeight } = this.canvas

    if (clientWidth === 0 || clientHeight === 0) {
      return
    }

    // Capped at two: beyond that the pixels are invisible and the fill cost is not.
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))
    this.renderer.setSize(clientWidth, clientHeight, false)

    this.cameras.setAspect(clientWidth / clientHeight)
    this.invalidate()
  }
}
