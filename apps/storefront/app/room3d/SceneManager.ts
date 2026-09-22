import type { Object3D } from 'three';
import {
  ACESFilmicToneMapping,
  AmbientLight,
  BufferGeometry,
  Color,
  DirectionalLight,
  Group,
  Mesh,
  NoToneMapping,
  Line,
  LineBasicMaterial,
  PCFSoftShadowMap,
  PMREMGenerator,
  Points,
  PointsMaterial,
  Scene,
  ShaderMaterial,
  SRGBColorSpace,
  Vector3,
  WebGLRenderer,
} from 'three'
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js'
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js'

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

  /**
   * The material the whole scene wears while a depth map is drawn.
   *
   * Written rather than taken from the library, because three's own depth material writes
   * the depth *buffer*, which is deliberately non-linear: almost all of its precision sits
   * in the first few centimetres in front of the camera so that near surfaces sort
   * correctly. Standing inside a five-metre room that produces an almost entirely black
   * frame — everything from one metre out is crushed into the same value, and a renderer
   * given it has nothing to follow. Measured in metres instead and spread evenly over the
   * room, which is what a depth control model is trained on.
   *
   * Near is white and far is black, the same way round as every published depth map.
   */
  private readonly depthMaterial = new ShaderMaterial({
    uniforms: { near: { value: 0.2 }, far: { value: 10 } },
    vertexShader: `
      varying float vDistance;

      void main() {
        vec4 seen = modelViewMatrix * vec4(position, 1.0);
        vDistance = -seen.z;
        gl_Position = projectionMatrix * seen;
      }
    `,
    fragmentShader: `
      uniform float near;
      uniform float far;
      varying float vDistance;

      void main() {
        float shade = 1.0 - clamp((vDistance - near) / (far - near), 0.0, 1.0);
        gl_FragColor = vec4(vec3(shade), 1.0);
      }
    `,
  })

  private room: Group | null = null

  /** The room as the photographs measured it, when somebody has asked to see it. */
  private scan: Points | null = null

  /** Kept so occlusion can be recomputed without asking the room its size every frame. */
  private geometry: RoomGeometry | null = null

  private frame: number | null = null

  /** Set whenever something moved; cleared once a frame has been drawn. */
  private dirty = true

  /** Told after each drawn frame, so HTML overlays can follow the camera. */
  private frameCallback: (() => void) | null = null

  private readonly resize: () => void

  private observer: ResizeObserver | null = null

  /** The last aspect the cameras were told, so a change of box refits the room once. */
  private aspect = 0

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

    /*
     * The box the canvas sits in changes size without the window doing so — a side column
     * appearing, a workspace filling whatever height the page gives it — and a window
     * `resize` event never fires for that. The observer sees the box itself.
     */
    if (typeof ResizeObserver !== 'undefined') {
      this.observer = new ResizeObserver(() => this.handleResize())
      this.observer.observe(this.canvas)
    }

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
   * Shows the room as the reconstruction measured it, instead of as we drew it.
   *
   * Six photographs handed to a reconstruction come back as a quarter of a million points in
   * three dimensions. It is not a tidy room — it is a cloud, with the far side of the sofa
   * missing and daylight smeared through the window — but it is *measured*, and the drawn room
   * beside it is a guess. Seeing the two is the whole point: the reading said this room was
   * 3.8 by 4.5 metres one time and 4.5 by 5.0 the next, and the cloud settles it.
   *
   * Drawn as points rather than as the mesh the file technically contains: it has no faces
   * worth shading, and a point cloud lit like furniture looks like a bug.
   */
  async showScan(url: string): Promise<void> {
    if (this.scan !== null) {
      this.hideScan()
    }

    const gltf = await new GLTFLoader().loadAsync(url)

    let geometry: BufferGeometry | null = null

    gltf.scene.traverse((node) => {
      if (geometry === null && node instanceof Mesh) geometry = node.geometry as BufferGeometry
    })

    if (geometry === null) {
      return
    }

    const cloud = geometry as BufferGeometry

    cloud.computeBoundingSphere()

    const radius = cloud.boundingSphere?.radius ?? 1

    /*
     * Scaled to the size of a room rather than to its own arbitrary units.
     *
     * The reconstruction has no idea how big anything is — it is faithful about shape and
     * silent about scale — so the cloud arrives about two units across. Fitting it to the room
     * we have makes the two comparable by eye, which is what this view is for.
     */
    const metres = this.geometry === null ? 5 : Math.max(this.geometry.width_mm, this.geometry.length_mm) / 1000
    const points = new Points(cloud, new PointsMaterial({ size: 0.012, vertexColors: cloud.getAttribute('color') !== undefined, color: 0x8a7f72 }))

    points.scale.setScalar(metres / (radius * 2))
    points.name = 'scan'

    this.scan = points
    this.scene.add(points)

    if (this.room !== null) this.room.visible = false

    this.layout.visible = false
    this.cameras.frameObject(points)
    this.invalidate()
  }

  /** Back to the room we drew. */
  hideScan(): void {
    if (this.scan !== null) {
      this.scene.remove(this.scan)
      this.scan.geometry.dispose()
      this.scan = null
    }

    if (this.room !== null) this.room.visible = true

    this.layout.visible = true

    if (this.geometry !== null) this.cameras.frame(this.geometry)

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

  /** The piece the resting pointer is over, lit a little, and the cursor saying it can be taken. */
  private hoveredId: string | null = null

  setHover(id: string | null, items: LayoutItem[], states: Map<string, CollisionState>, selectedId: string | null): void {
    if (id === this.hoveredId) {
      return
    }

    const previous = this.hoveredId
    this.hoveredId = id

    for (const candidate of [previous, id]) {
      if (candidate === null) {
        continue
      }

      const group = this.pieces.get(candidate)
      const item = items.find(entry => entry.id === candidate)

      if (group !== undefined && item !== undefined) {
        this.furniture.paint(group, item, states.get(candidate) ?? 'ok', candidate === selectedId, candidate === id)
      }
    }

    this.invalidate()
  }

  /**
   * What the pointer looks like over the canvas.
   *
   * Set by whoever knows what the gesture would do, rather than inferred here from hover
   * alone. Hover used to be the only input and the cursor said "grab" over everything it
   * could see, including a piece somebody had locked — which then refused to move, with no
   * warning until it did not budge.
   */
  setCursor(cursor: string): void {
    this.canvas.style.cursor = cursor
  }

  /** Whether a wall is currently drawn, or hidden so the room can be looked into. */
  wallVisible(name: string): boolean {
    return this.room?.getObjectByName(`wall-${name}`)?.visible ?? false
  }

  /** The walls, casings and all, for a door or window being dragged onto one. */
  walls(): Mesh[] {
    return (this.room?.children ?? []).filter((child): child is Mesh => child instanceof Mesh && typeof child.userData.wall === 'string')
  }

  /** Everything in the room group, for finding the opening under the pointer. */
  roomObjects(): Object3D[] {
    return this.room?.children ?? []
  }

  /**
   * Rebuilds the room with the openings where they are now, and leaves the camera alone.
   *
   * `setRoom` frames the camera on the room, which is right when the size changes and wrong
   * while a door is being dragged: the view would jump on every pointer move.
   */
  rebuildRoom(openings: RoomOpening[]): void {
    if (this.geometry === null) {
      return
    }

    if (this.room !== null) {
      this.scene.remove(this.room)
      this.rooms.dispose(this.room)
    }

    this.room = this.rooms.build(this.geometry, openings)
    this.scene.add(this.room)
    this.invalidate()
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
   *
   * A wall taken away leaves its bottom 40 cm behind. The product owner orbited their room,
   * saw the television unit standing at the edge of a bare floor with nothing behind it, and
   * said the arrangement was wrong — it was not, but what made it read as "against the wall"
   * was the wall, and we had removed it. A knee-high remnant keeps the room a room from every
   * angle and still lets the camera see in.
   */
  private updateOcclusion(): void {
    if (this.room === null || this.geometry === null) {
      return
    }

    const camera = this.cameras.active.position
    const inside = this.cameras.mode === 'inside'

    const width = toUnits(this.geometry.width_mm)
    const length = toUnits(this.geometry.length_mm)

    // Whether the camera is on the room's side of each wall.
    const behind: Record<string, boolean> = {
      north: camera.z >= 0,
      south: camera.z <= length,
      west: camera.x >= 0,
      east: camera.x <= width,
    }

    for (const child of this.room.children) {
      if (child.name === 'ceiling') {
        child.visible = inside

        continue
      }

      const wall = child.name.replace(/^(wall|stub)-/, '')
      const keeps = behind[wall]

      if (keeps === undefined) {
        continue
      }

      child.visible = child.name.startsWith('stub-')
        ? !inside && !keeps
        : inside || keeps
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

  /**
   * The same frame as a depth map: near is white, far is black.
   *
   * This is the room as a structure rather than as a picture, and it is what a renderer can
   * be *made* to obey. A colour screenshot is a reference — a model looks at it, takes the
   * idea and resolves the rest however it likes, which is how a design came back with the
   * window on a different wall and an armchair nobody sells. A depth map goes in as a
   * control image, and then the walls, the openings and every piece of furniture are where
   * the customer's own room put them, because the geometry is an input and not a suggestion.
   *
   * Drawn by swapping one material over the whole scene rather than by keeping a second
   * renderer: same aspect, same pixels.
   *
   * **From inside the room, whatever the customer is looking at.** The first version used
   * whichever camera was on screen, which is usually the doll's-house view — and a
   * doll's-house depth map is a box floating in a void with the outside faces of two walls
   * towards you. A renderer asked for "a photorealistic interior photograph" and handed that
   * produced exactly what it describes: a cut-open box seen from the outside of a house,
   * with the void filled in as another room. The control image has to be the kind of picture
   * the answer is meant to be, so it is taken from inside, where the frame is all room and
   * there is no void to interpret.
   *
   * Returns an empty string when the canvas has no size — the plan view is showing, and a
   * depth map of nothing is worse than no depth map.
   */
  structureSnapshot(): { inside: string, depth: string } {
    if (this.canvas.clientWidth === 0 || this.canvas.clientHeight === 0) {
      return { inside: '', depth: '' }
    }

    const shown = this.cameras.mode

    if (shown !== 'inside') {
      this.cameras.setMode('inside')
    }

    /*
     * The walls back, before the frame is drawn.
     *
     * Occlusion is decided once per frame from where the camera is, and the loop has not
     * run since the camera moved indoors — so the two walls hidden for the doll's-house
     * view are still hidden, and a depth map of a room with two missing walls is half a
     * frame of void. Standing inside, every wall and the ceiling are part of the room.
     */
    this.updateOcclusion()

    const camera = this.cameras.active
    const background = this.scene.background
    const tone = this.renderer.toneMapping

    /*
     * The depth range pulled in around the room before the frame is drawn.
     *
     * MeshDepthMaterial writes the depth buffer, which spreads the camera's whole near-to-far
     * range across 0 to 1. With a far plane set for comfortable orbiting, a five-metre room
     * occupies a sliver of that range and comes out as one flat grey. Clamped to the room and
     * restored afterwards, the same five metres fill the range and the map has contrast a
     * control model can actually read.
     */
    /*
     * The range the shading spreads over: arm's length to the far corner.
     *
     * Not the camera's own near and far, which exist to make sorting work and to let
     * somebody orbit; those are left alone. This is the range the *picture* uses, so the
     * room fills it and the map has the contrast a control model needs.
     */
    const reach = this.room === null ? 12 : this.roomReach()

    /*
     * The colour frame first, from exactly this camera.
     *
     * The renderer is given both: the depth map as the constraint and this as the material
     * it starts from. They have to be the same view or they contradict each other, and a
     * model told to follow two different rooms follows neither — which is why they are taken
     * together here rather than separately from wherever each camera happened to be.
     */
    this.render()

    const inside = this.canvas.toDataURL('image/png')

    this.depthMaterial.uniforms.near!.value = 0.2
    this.depthMaterial.uniforms.far!.value = reach

    this.scene.overrideMaterial = this.depthMaterial
    this.scene.background = null
    // Tone mapping is for photographs of a room; a depth map is a measurement drawn as grey.
    this.renderer.toneMapping = NoToneMapping

    this.renderer.render(this.scene, camera)

    const map = this.canvas.toDataURL('image/png')

    this.scene.overrideMaterial = null
    this.scene.background = background
    this.renderer.toneMapping = tone

    if (shown !== 'inside') {
      this.cameras.setMode(shown)
    }

    // The customer's own view back in the buffer, so a snapshot taken straight after this
    // one is a picture of the room they were looking at and not a grey one.
    this.render()

    return { inside, depth: map }
  }

  /** Half the room's longest diagonal, for clamping the depth range around it. */
  private roomReach(): number {
    if (this.geometry === null) {
      return 12
    }

    const width = toUnits(this.geometry.width_mm)
    const length = toUnits(this.geometry.length_mm)
    const height = toUnits(this.geometry.height_mm)

    return Math.sqrt(width * width + length * length + height * height)
  }

  dispose(): void {
    if (this.frame !== null) {
      cancelAnimationFrame(this.frame)
      this.frame = null
    }

    window.removeEventListener('resize', this.resize)
    this.observer?.disconnect()
    this.observer = null

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
    this.depthMaterial.dispose()

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

    const aspect = clientWidth / clientHeight

    this.cameras.setAspect(aspect)

    // A box of a different shape shows a different amount of room: a wide one cuts the
    // ceiling, a tall one leaves the room small in the middle. Refit it, once per change.
    if (this.aspect !== 0 && Math.abs(aspect - this.aspect) > 0.01) {
      this.cameras.reframe()
    }

    this.aspect = aspect
    this.invalidate()
  }
}
