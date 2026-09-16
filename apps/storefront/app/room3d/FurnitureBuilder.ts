import {
  Box3,
  BoxGeometry,
  CanvasTexture,
  Color,
  EdgesGeometry,
  Group,
  LineBasicMaterial,
  LineSegments,
  Mesh,
  MeshBasicMaterial,
  MeshStandardMaterial,
  PlaneGeometry,
  Vector3,
  type Object3D,
} from 'three'

import { MeshoptDecoder } from 'three/examples/jsm/libs/meshopt_decoder.module.js'
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js'

import type { CollisionState } from './CollisionEngine'
import { buildShape, shapeFor } from './PlaceholderShapes'
import { photographColour } from './ProductCutout'
import { isMeasured } from './footprint'
import { type LayoutItem, toUnits } from './types'

/**
 * Furniture: the product's 3D model when the catalogue has one, and an honest shape when not.
 *
 * Three ways of drawing a product without a model have been tried here and two are gone. A box
 * with the photograph on its front was "a warehouse of cartons". The photograph cut out and
 * stood on its footprint was a flat picture that turned to face whoever looked, and in the
 * product owner's screenshot a round table became a red plate — "ben bu şekilde istemedim".
 * A picture is not a thing in a room, however it is held up.
 *
 * So a piece is two things:
 *
 * **The footprint**, a low slab at the variant's real width and depth. It carries the
 * collision colour, it turns when the piece turns, and it is what proves the thing fits.
 * Dimensions come from the SKU and nowhere else — a sofa is 2200 mm or 2600 mm depending on
 * which one was chosen, so a box drawn from the product is a box drawn from an average of
 * things the customer did not buy.
 *
 * **The body**: the glTF model scaled to those dimensions, or, until there is one, a shape of
 * the product's kind in the product's own colour — a seat with a back and arms, a top on
 * legs, a carcass on a plinth. Solid, shadowed, facing the way the piece faces, and never
 * turning to face the camera. Plain on purpose: it is a stand-in and looks like one, and the
 * model replaces it the moment the catalogue has one.
 */
export class FurnitureBuilder {
  /**
   * What an unmeasured piece is drawn as, in millimetres.
   *
   * Deliberately a cube, and deliberately not a plausible sofa. It has to read as "we do not
   * know how big this is" at a glance — a placeholder that looks like a measurement is a
   * measurement the customer will trust.
   */
  private static readonly PLACEHOLDER_MM = 600

  /** Height used when the variant has width and depth but no height. */
  private static readonly DEFAULT_HEIGHT_MM = 700

  /** How thick the footprint slab under a cut-out is. */
  private static readonly PAD_MM = 12

  /**
   * One material per state, shared by every piece in that state.
   *
   * A material per mesh is a shader program per mesh, which is the difference between a room
   * that opens and a room that stutters for a second first.
   */
  private readonly materials: Record<CollisionState | 'placeholder', MeshStandardMaterial> = {
    ok: new MeshStandardMaterial({ color: 0xa8927a, roughness: 0.7, metalness: 0 }),
    // Amber and red rather than two shades of one colour: the difference between "you should
    // know" and "this cannot be delivered" has to survive a glance and a colour-blind viewer,
    // which is why the panel says it in words as well.
    warning: new MeshStandardMaterial({ color: 0xd4a02a, roughness: 0.7, metalness: 0 }),
    blocked: new MeshStandardMaterial({ color: 0xc2453a, roughness: 0.7, metalness: 0 }),
    placeholder: new MeshStandardMaterial({
      color: 0x9aa0a6,
      roughness: 1,
      metalness: 0,
      transparent: true,
      opacity: 0.45,
    }),
  }

  /**
   * The placeholder materials, by photograph: the product's colour and a darker accent.
   *
   * Four dining chairs are one photograph and one colour, so the pair is shared; and the
   * promise is cached rather than the result, so four chairs added at once read the photograph
   * once rather than four times.
   */
  private readonly palettes = new Map<string, Promise<{ body: MeshStandardMaterial, accent: MeshStandardMaterial }>>()

  /** What a product with no photograph at all is drawn in: warm, neutral, obviously a stand-in. */
  private readonly neutral = {
    body: new MeshStandardMaterial({ color: 0xb9a48c, roughness: 0.85, metalness: 0 }),
    accent: new MeshStandardMaterial({ color: 0x6b5d4f, roughness: 0.85, metalness: 0 }),
  }

  /**
   * Loads the glTF binaries the catalogue has, when it has them.
   *
   * With the meshopt decoder, because stored models are meshopt-compressed by the mesh-tools
   * sidecar — a fifth of the bytes for the same sofa. The decoder is plain WebAssembly inlined
   * in the module, so there is no decoder file to serve and nothing to go missing on a CDN.
   */
  private readonly models = new GLTFLoader().setMeshoptDecoder(MeshoptDecoder)

  private readonly edgeMaterial = new LineBasicMaterial({ color: 0x3d3733 })

  private readonly selectedEdgeMaterial = new LineBasicMaterial({ color: 0xb08f52, linewidth: 2 })

  private readonly hoverEdgeMaterial = new LineBasicMaterial({ color: 0x8c7141 })

  /** The outline of what a piece takes up on the floor, shown for the selected one. */
  private readonly footprintMaterial = new LineBasicMaterial({ color: 0xb08f52, transparent: true, opacity: 0.9 })

  /**
   * A soft dark disc under everything that stands on the floor.
   *
   * The sun's shadow is one hard edge in one direction; the darkening right under a piece,
   * where the floor gets no light from anywhere, is what makes it sit on the floor instead
   * of hovering a millimetre above it. Painted once, on a canvas, and shared.
   */
  private readonly contactShadowMaterial = new MeshBasicMaterial({
    map: FurnitureBuilder.contactShadowTexture(),
    transparent: true,
    opacity: 0.38,
    depthWrite: false,
  })

  private static contactShadowTexture(): CanvasTexture {
    const size = 128
    const canvas = document.createElement('canvas')
    canvas.width = size
    canvas.height = size

    const context = canvas.getContext('2d')!
    const gradient = context.createRadialGradient(size / 2, size / 2, size * 0.18, size / 2, size / 2, size / 2)

    gradient.addColorStop(0, 'rgba(0,0,0,0.9)')
    gradient.addColorStop(0.55, 'rgba(0,0,0,0.35)')
    gradient.addColorStop(1, 'rgba(0,0,0,0)')

    context.fillStyle = gradient
    context.fillRect(0, 0, size, size)

    return new CanvasTexture(canvas)
  }

  /** @param onDressed called when a model or shape has replaced a box, so the scene redraws */
  constructor(private readonly onDressed: () => void = () => {}) {}

  /**
   * One piece, at its position, ready to add to the scene.
   *
   * The group's origin is the piece's centre on the floor, which is where the API says it is
   * and where rotation happens.
   */
  build(item: LayoutItem): Group {
    const group = new Group()

    group.name = `item-${item.id}`
    group.userData.itemId = item.id

    const measured = isMeasured(item)

    const width = measured ? (item.width_mm ?? 0) : FurnitureBuilder.PLACEHOLDER_MM
    const depth = measured ? (item.depth_mm ?? 0) : FurnitureBuilder.PLACEHOLDER_MM
    const height = measured
      ? (item.height_mm ?? FurnitureBuilder.DEFAULT_HEIGHT_MM)
      : FurnitureBuilder.PLACEHOLDER_MM

    /*
     * The solid box, until a cut-out arrives.
     *
     * Built for every piece rather than only for the ones that fail, because the photograph
     * takes a moment to load and cut: a room that is empty for half a second and then fills
     * with furniture looks broken, and one that shows blocks and then sharpens into furniture
     * looks like it is working.
     */
    const geometry = new BoxGeometry(toUnits(width), toUnits(height), toUnits(depth))

    const body = new Mesh(geometry, measured ? this.materials.ok : this.materials.placeholder)
    body.name = 'body'
    body.castShadow = measured
    body.receiveShadow = true
    body.position.y = toUnits(height) / 2
    body.userData.itemId = item.id

    const edges = new LineSegments(new EdgesGeometry(geometry), this.edgeMaterial)
    edges.name = 'edges'
    edges.position.y = body.position.y

    group.add(body, edges)

    // Only for what stands on the floor: a picture on the wall casts nothing on it.
    if (measured && item.position_y_mm === 0) {
      const shadow = new Mesh(new PlaneGeometry(toUnits(width) * 1.25, toUnits(depth) * 1.25), this.contactShadowMaterial)
      shadow.name = 'shadow'
      shadow.rotation.x = -Math.PI / 2
      shadow.position.y = 0.004
      shadow.renderOrder = 1
      group.add(shadow)

      const outline = new PlaneGeometry(toUnits(width), toUnits(depth))
      const footprint = new LineSegments(new EdgesGeometry(outline), this.footprintMaterial)
      footprint.name = 'footprint'
      footprint.rotation.x = -Math.PI / 2
      footprint.position.y = 0.006
      footprint.visible = false
      outline.dispose()
      group.add(footprint)
    }

    this.place(group, item)

    /*
     * A real model if the catalogue has one, a shape of its kind if not.
     *
     * Both replace the box that was just built, and the box exists so that a room is never
     * empty while a file is on its way: blocks that sharpen into furniture look like a page
     * working, and an empty room that fills half a second later looks like one that broke.
     */
    if (measured && item.model_url !== null) {
      void this.dressWithModel(group, item, width, height, depth)
    }
    else if (measured) {
      void this.dressWithShape(group, item, width, height, depth)
    }

    return group
  }

  /**
   * Moves a piece without rebuilding it.
   *
   * Called on every frame of a drag, so it allocates nothing: rebuilding a box geometry sixty
   * times a second is sixty buffers for the garbage collector to find something to do with,
   * and it shows up as a stutter halfway through the gesture.
   */
  place(group: Group, item: LayoutItem, at?: { x: number, z: number, rotation?: number }): void {
    group.position.set(
      toUnits(at?.x ?? item.position_x_mm),
      toUnits(item.position_y_mm),
      toUnits(at?.z ?? item.position_z_mm),
    )

    /*
     * Negative, because the two coordinate systems turn opposite ways.
     *
     * The plan's degrees go clockwise seen from above — which is how anybody describes turning
     * a sofa — and Three.js's y rotation goes anticlockwise. The sign is invisible on a box
     * and is not on a piece with a front.
     */
    group.rotation.y = (-(at?.rotation ?? item.rotation_y_deg) * Math.PI) / 180
  }

  /**
   * Recolours a piece for its state, outlines it when it is the one selected, and lifts it
   * a little when the pointer rests on it.
   */
  paint(group: Group, item: LayoutItem, state: CollisionState, selected: boolean, hovered = false): void {
    const body = group.getObjectByName('body')
    const edges = group.getObjectByName('edges')
    const footprint = group.getObjectByName('footprint')

    if (body instanceof Mesh) {
      body.material = isMeasured(item) ? this.materials[state] : this.materials.placeholder
    }

    if (edges instanceof LineSegments) {
      edges.material = selected ? this.selectedEdgeMaterial : hovered ? this.hoverEdgeMaterial : this.edgeMaterial
    }

    // The footprint on the floor is the selection's: it says exactly what the piece takes
    // up, which the piece itself — a sofa with arms, a lamp on a stem — does not.
    if (footprint !== undefined) {
      footprint.visible = selected || hovered
    }
  }

  /**
   * Frees a piece's geometry.
   *
   * Materials are shared and deliberately not touched here — disposing one would blank every
   * other piece in the same state, or every other copy of the same product.
   */
  dispose(object: Object3D): void {
    object.traverse((child) => {
      if (child instanceof Mesh || child instanceof LineSegments) {
        child.geometry.dispose()
      }
    })
  }

  /** Called once when the editor closes, after every piece has gone. */
  disposeMaterials(): void {
    for (const material of Object.values(this.materials)) {
      material.dispose()
    }

    for (const palette of this.palettes.values()) {
      void palette.then(({ body, accent }) => {
        body.dispose()
        accent.dispose()
      })
    }

    this.palettes.clear()

    this.neutral.body.dispose()
    this.neutral.accent.dispose()

    this.edgeMaterial.dispose()
    this.selectedEdgeMaterial.dispose()
  }

  // --- internals -------------------------------------------------------------

  /**
   * Replaces the box with the product's actual 3D model.
   *
   * **Scaled to the variant's recorded dimensions, never to its own.** A generated mesh
   * arrives in whatever units the generator felt like and with whatever proportions it
   * inferred from one photograph; the catalogue knows the thing is 2200 mm wide because a
   * seller measured it. Believing the mesh instead would be the "beautiful model at the
   * wrong size" that makes a planner worse than a box — it would look convincing and it
   * would not fit.
   *
   * Proportional: the largest dimension decides the scale and the other two follow, so a
   * mesh whose depth is a little off stays a sofa rather than becoming a squashed one.
   */
  private async dressWithModel(
    group: Group,
    item: LayoutItem,
    width: number,
    height: number,
    depth: number,
  ): Promise<void> {
    const url = item.model_url

    if (url === null) {
      return
    }

    let scene: Group

    try {
      const loaded = await this.models.loadAsync(url)

      scene = loaded.scene
    }
    catch {
      // No model after all. The shape is the next best thing and the box is behind it.
      void this.dressWithShape(group, item, width, height, depth)

      return
    }

    // The piece may have been removed while the file was on its way.
    if (group.parent === null) {
      return
    }

    const bounds = new Box3().setFromObject(scene)
    const size = bounds.getSize(new Vector3())

    if (size.x <= 0 || size.y <= 0 || size.z <= 0) {
      return
    }

    const scale = Math.min(
      toUnits(width) / size.x,
      toUnits(height) / size.y,
      toUnits(depth) / size.z,
    )

    scene.scale.setScalar(scale)

    // Stood on the floor and centred on the item's own origin: a mesh is modelled around
    // whatever point its author chose, and that is rarely the middle of its base.
    const scaled = new Box3().setFromObject(scene)
    const centre = scaled.getCenter(new Vector3())

    scene.position.set(-centre.x, -scaled.min.y, -centre.z)

    scene.name = 'model'
    scene.userData.itemId = item.id

    scene.traverse((child) => {
      if (child instanceof Mesh) {
        child.castShadow = true
        child.receiveShadow = true
        child.userData.itemId = item.id
      }
    })

    this.flatten(group, width, depth)

    group.add(scene)

    this.onDressed()
  }

  /**
   * Replaces the box with a shape of the product's kind, in the product's own colour.
   *
   * The box does not disappear: it becomes the footprint slab, which is what carries the
   * collision colour and proves the piece fits. What goes is its bulk — the carton — and what
   * arrives is a sofa-shaped, table-shaped, wardrobe-shaped stand-in that sits in the room the
   * way the real thing will.
   */
  private async dressWithShape(
    group: Group,
    item: LayoutItem,
    width: number,
    height: number,
    depth: number,
  ): Promise<void> {
    const palette = item.image_url === null ? this.neutral : await this.paletteFor(item.image_url)

    // The piece may have been removed while the photograph was being read.
    if (group.parent === null) {
      return
    }

    this.flatten(group, width, depth)

    const shape = buildShape(
      shapeFor(item.category),
      toUnits(width),
      toUnits(height),
      toUnits(depth),
      palette.body,
      palette.accent,
    )

    shape.name = 'shape'
    shape.userData.itemId = item.id

    shape.traverse((child) => {
      child.userData.itemId = item.id
    })

    group.add(shape)

    this.onDressed()
  }

  /**
   * Turns the solid box into the footprint slab under a product.
   *
   * The bulk was the part that looked like a carton; the footprint is the honest part, and
   * it carries the collision colour, turns with the piece and proves the thing fits.
   */
  private flatten(group: Group, width: number, depth: number): void {
    const pad = new BoxGeometry(toUnits(width), toUnits(FurnitureBuilder.PAD_MM), toUnits(depth))

    const body = group.getObjectByName('body')

    if (body instanceof Mesh) {
      body.geometry.dispose()
      body.geometry = pad
      body.position.y = toUnits(FurnitureBuilder.PAD_MM) / 2
      body.castShadow = false
    }

    const edges = group.getObjectByName('edges')

    if (edges instanceof LineSegments) {
      edges.geometry.dispose()
      edges.geometry = new EdgesGeometry(pad)
      edges.position.y = toUnits(FurnitureBuilder.PAD_MM) / 2
    }
  }

  /**
   * The colour pair for a photograph, read once and shared by every copy of the product.
   *
   * The accent is the same colour darkened, not a second colour: legs and plinths in a related
   * shade read as parts of one object, where a grey leg under a blue sofa reads as two.
   */
  private paletteFor(url: string): Promise<{ body: MeshStandardMaterial, accent: MeshStandardMaterial }> {
    const cached = this.palettes.get(url)

    if (cached !== undefined) {
      return cached
    }

    const request = photographColour(url).then((colour) => {
      if (colour === null) {
        return this.neutral
      }

      const body = new Color(colour.r, colour.g, colour.b)

      // A photograph's dominant colour is often near-white (the sweep bleeds in) or very dark;
      // pulled towards a mid tone so a white sofa is still a visible object on a pale floor.
      const hsl = { h: 0, s: 0, l: 0 }

      body.getHSL(hsl)
      body.setHSL(hsl.h, Math.min(hsl.s, 0.6), Math.min(Math.max(hsl.l, 0.28), 0.78))

      const accent = body.clone().multiplyScalar(0.55)

      return {
        body: new MeshStandardMaterial({ color: body, roughness: 0.85, metalness: 0 }),
        accent: new MeshStandardMaterial({ color: accent, roughness: 0.85, metalness: 0 }),
      }
    })

    this.palettes.set(url, request)

    return request
  }
}
