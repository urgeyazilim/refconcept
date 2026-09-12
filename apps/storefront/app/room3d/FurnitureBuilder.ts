import {
  Box3,
  BoxGeometry,
  ClampToEdgeWrapping,
  Color,
  DoubleSide,
  EdgesGeometry,
  Group,
  LineBasicMaterial,
  LineSegments,
  Mesh,
  MeshStandardMaterial,
  PlaneGeometry,
  Vector3,
  type Object3D,
} from 'three'

import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js'

import type { CollisionState } from './CollisionEngine'
import { cutOut } from './ProductCutout'
import { isMeasured } from './footprint'
import { type LayoutItem, toUnits } from './types'

/**
 * Furniture: the product's own photograph, cut out and standing on its footprint.
 *
 * The first version drew a box at the right size with the photograph on its front face. It
 * was honest and it looked like a warehouse of cartons — which is exactly what a customer
 * said when they saw it. A catalogue photograph is a sofa on a white sweep; pasted on a box
 * it reads as a box with a picture on it, and cut out it reads as a sofa.
 *
 * So a piece is two things:
 *
 * **The footprint**, a low slab at the variant's real width and depth. It carries the
 * collision colour, it turns when the piece turns, and it is what proves the thing fits.
 * Dimensions come from the SKU and nowhere else — a sofa is 2200 mm or 2600 mm depending on
 * which one was chosen, so a box drawn from the product is a box drawn from an average of
 * things the customer did not buy.
 *
 * **The cut-out**, a plane at the real width and height, turned to face the camera about the
 * vertical axis only. Facing the camera because a photograph seen edge-on is a line; about
 * one axis only because a plan view seen from above should show the footprint, not a sofa
 * lying on the floor looking up.
 *
 * When the photograph cannot be cut — no CORS headers, or a picture taken in a room rather
 * than on a sweep — the piece falls back to the solid box. A worse picture beats a sofa with
 * a bite out of it.
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
   * The cut-out materials, by photograph.
   *
   * Four dining chairs are one photograph and one cut; the plane's own size and crop live on
   * the geometry and the texture's repeat, so the material itself is shared.
   */
  private readonly cutouts = new Map<string, MeshStandardMaterial>()

  /** Requests in flight, so four chairs added at once make one request rather than four. */
  private readonly pending = new Map<string, Promise<MeshStandardMaterial | null>>()

  /** Loads the glTF binaries the catalogue has, when it has them. */
  private readonly models = new GLTFLoader()

  private readonly edgeMaterial = new LineBasicMaterial({ color: 0x3d3733 })

  private readonly selectedEdgeMaterial = new LineBasicMaterial({ color: 0xb08f52, linewidth: 2 })

  constructor(private readonly onCutoutReady: () => void = () => {}) {}

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

    // Read back per frame, to narrow the cut-out to the shadow the piece would really cast.
    group.userData.widthMm = width
    group.userData.depthMm = depth

    group.add(body, edges)

    this.place(group, item)

    /*
     * A real model if the catalogue has one, its own photograph if not.
     *
     * Both replace the box that was just built, and the box exists so that a room is never
     * empty while a file is on its way: blocks that sharpen into furniture look like a page
     * working, and an empty room that fills half a second later looks like one that broke.
     */
    if (measured && item.model_url !== null) {
      void this.dressWithModel(group, item, width, height, depth)
    }
    else if (measured && item.image_url !== null) {
      void this.dressWithPhotograph(group, item, width, height, depth)
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
   * Turns every cut-out to face the camera, about the vertical axis only.
   *
   * Called once per drawn frame. A photograph seen edge-on is a line, and a photograph that
   * tips towards a camera looking down is a sofa lying on the floor looking up — so the plane
   * turns about y and about nothing else. Seen from directly above it does become a line,
   * which is correct: the plan view is about the footprint.
   */
  faceCamera(group: Group, cameraX: number, cameraZ: number): void {
    const cutout = group.getObjectByName('cutout')

    if (cutout === undefined) {
      return
    }

    const towards = Math.atan2(cameraX - group.position.x, cameraZ - group.position.z)

    // Minus the group's own turn, because the plane is a child of it and inherits that.
    const relative = Math.atan2(
      Math.sin(towards - group.rotation.y),
      Math.cos(towards - group.rotation.y),
    )

    cutout.rotation.y = relative

    /*
     * Narrowed to the shadow the real piece would cast towards the camera.
     *
     * A billboard that turns fully and keeps its width is a 1.8 m bookcase swinging to face
     * whoever is looking — and standing against a wall, sticking half of itself through it.
     * Clamping the turn instead leaves pieces edge-on and paper-thin.
     *
     * Both are solved by the same line: as the piece turns, its plane narrows to the width a
     * box of its footprint would actually present from that angle — its full width seen
     * head-on, its depth seen from the side. It always faces the camera, and it never covers
     * more floor than it occupies.
     */
    const width = Number(group.userData.widthMm ?? 0)
    const depth = Number(group.userData.depthMm ?? 0)

    if (width > 0) {
      const silhouette = Math.abs(width * Math.cos(relative)) + Math.abs(depth * Math.sin(relative))

      cutout.scale.x = silhouette / width
    }
  }

  /** Recolours a piece for its state, and outlines it when it is the one selected. */
  paint(group: Group, item: LayoutItem, state: CollisionState, selected: boolean): void {
    const body = group.getObjectByName('body')
    const edges = group.getObjectByName('edges')

    if (body instanceof Mesh) {
      body.material = isMeasured(item) ? this.materials[state] : this.materials.placeholder
    }

    if (edges instanceof LineSegments) {
      edges.material = selected ? this.selectedEdgeMaterial : this.edgeMaterial
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

    for (const material of this.cutouts.values()) {
      material.map?.dispose()
      material.dispose()
    }

    this.cutouts.clear()
    this.pending.clear()

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
      // No model after all. The photograph is the next best thing and the box is behind it.
      if (item.image_url !== null) {
        void this.dressWithPhotograph(group, item, width, height, depth)
      }

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

    this.onCutoutReady()
  }

  /**
   * Replaces the box with the product, once its photograph has been cut out.
   *
   * The box does not disappear: it becomes the footprint slab, which is what carries the
   * collision colour and proves the piece fits. What goes is its bulk — the thing that made
   * a furnished room look like a stack of cartons.
   */
  private async dressWithPhotograph(
    group: Group,
    item: LayoutItem,
    width: number,
    height: number,
    depth: number,
  ): Promise<void> {
    const url = item.image_url

    if (url === null) {
      return
    }

    const material = await this.cutoutFor(url, width / height)

    // The piece may have been removed while the photograph was loading.
    if (material === null || group.parent === null) {
      return
    }

    this.flatten(group, width, depth)

    const plane = new Mesh(new PlaneGeometry(toUnits(width), toUnits(height)), material)

    plane.name = 'cutout'
    plane.position.y = toUnits(height) / 2
    plane.castShadow = true
    plane.userData.itemId = item.id

    group.add(plane)

    this.onCutoutReady()
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
   * The cut-out material for a photograph, made once and shared.
   *
   * The crop lives on the texture, which is shared too — every copy of the same product is
   * the same size, so the same crop is right for all of them.
   */
  private async cutoutFor(url: string, faceAspect: number): Promise<MeshStandardMaterial | null> {
    // Keyed by the shape of the plane as well as the photograph: the crop that fits a sofa
    // does not fit a bedside table, and the same picture is never both.
    const key = `${url}|${faceAspect.toFixed(2)}`

    const ready = this.cutouts.get(key)

    if (ready !== undefined) {
      return ready
    }

    const inFlight = this.pending.get(key)

    if (inFlight !== undefined) {
      return await inFlight
    }

    const request = cutOut(url).then((cut) => {
      if (cut === null) {
        return null
      }

      const texture = cut.texture.clone()

      texture.needsUpdate = true
      texture.wrapS = ClampToEdgeWrapping
      texture.wrapT = ClampToEdgeWrapping

      const material = new MeshStandardMaterial({
        map: texture,
        color: new Color(0xffffff),
        roughness: 1,
        metalness: 0,
        transparent: true,
        /*
         * Cut out rather than blended.
         *
         * `alphaTest` makes each pixel either there or not, which means the plane writes
         * depth and can be behind and in front of things correctly. Blended transparency
         * would need the whole scene sorted back to front, and furniture would flicker
         * through furniture as the camera moved.
         */
        alphaTest: 0.5,
        // Both faces: a cut-out is a photograph, and walking round the back of a sofa should
        // show the sofa rather than nothing at all.
        side: DoubleSide,
      })

      this.cutouts.set(key, material)

      return material
    })

    this.pending.set(key, request)

    return await request
  }
}
