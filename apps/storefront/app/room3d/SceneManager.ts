import type {
  Group} from 'three';
import {
  ACESFilmicToneMapping,
  AmbientLight,
  Color,
  DirectionalLight,
  PCFSoftShadowMap,
  Scene,
  SRGBColorSpace,
  WebGLRenderer,
} from 'three'

import { CameraManager } from './CameraManager'
import { RoomGeometryBuilder } from './RoomGeometryBuilder'
import { type RoomGeometry, type RoomOpening, type ViewMode, toUnits } from './types'

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

  private room: Group | null = null

  /** Kept so occlusion can be recomputed without asking the room its size every frame. */
  private geometry: RoomGeometry | null = null

  private frame: number | null = null

  /** Set whenever something moved; cleared once a frame has been drawn. */
  private dirty = true

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
    // Drawn synchronously first: the loop may not have run since the last change, and a
    // screenshot of a stale frame is a screenshot of the wrong layout.
    this.render()

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
    this.scene.add(new AmbientLight(0xffffff, 1.4))

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
