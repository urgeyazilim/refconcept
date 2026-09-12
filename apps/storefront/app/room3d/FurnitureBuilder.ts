import {
  BoxGeometry,
  EdgesGeometry,
  Group,
  LineBasicMaterial,
  LineSegments,
  Mesh,
  MeshStandardMaterial,
  SRGBColorSpace,
  TextureLoader,
  type Object3D,
} from 'three'

import type { CollisionState } from './CollisionEngine'
import { isMeasured } from './footprint'
import { type LayoutItem, toUnits } from './types'

/**
 * Furniture, as boxes.
 *
 * Boxes with the product's own photograph on the front.
 *
 * Boxes on purpose, for now. The catalogue has photographs and prices for everything and 3D
 * models for almost nothing, and a planner that waits for models is a planner nobody can use
 * this year. A box at the exact size of the real piece answers the question the plan is for —
 * does it fit, can you walk past it — and answers it honestly. A beautifully modelled sofa at
 * the wrong dimensions would look far better and be worth less than nothing.
 *
 * The photograph is what makes a box recognisable as the thing that was chosen. On the front
 * face only: wrapped round all six it reads as a printed carton.
 *
 * The edges are drawn as lines over the box because an untextured box under soft light has
 * corners that disappear, and the corner is the part somebody is trying to see.
 *
 * Sizes come from the SKU, never from the product. A sofa is 2200 mm or 2600 mm depending on
 * which variant was chosen, so a box drawn from the product is a box drawn from an average of
 * things the customer did not buy.
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
   * The product photographs, by URL.
   *
   * Four dining chairs are one photograph, and four downloads would be three too many.
   */
  private readonly photographs = new Map<string, MeshStandardMaterial>()

  private readonly textures = new TextureLoader()

  private readonly edgeMaterial = new LineBasicMaterial({ color: 0x3d3733 })

  private readonly selectedEdgeMaterial = new LineBasicMaterial({ color: 0xb08f52, linewidth: 2 })

  constructor(private readonly onTextureLoaded: () => void = () => {}) {}

  /**
   * One piece, at its position, ready to add to the scene.
   *
   * The group's origin is the piece's centre on the floor, which is where the API says it is
   * and where rotation happens. The mesh inside is lifted by half its height so the box
   * stands on the floor rather than being buried to its waist in it.
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

    const geometry = new BoxGeometry(toUnits(width), toUnits(height), toUnits(depth))

    const mesh = new Mesh(geometry, this.facesFor(item, measured ? 'ok' : 'placeholder'))
    mesh.name = 'body'
    mesh.castShadow = measured
    mesh.receiveShadow = true
    mesh.position.y = toUnits(height) / 2
    mesh.userData.itemId = item.id

    const edges = new LineSegments(new EdgesGeometry(geometry), this.edgeMaterial)
    edges.name = 'edges'
    edges.position.y = mesh.position.y

    group.add(mesh, edges)

    this.place(group, item)

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
     * and will not be on the first piece with a front.
     */
    group.rotation.y = (-(at?.rotation ?? item.rotation_y_deg) * Math.PI) / 180
  }

  /**
   * The six faces of a piece, with its photograph on the front.
   *
   * A room of anonymous boxes at the right sizes answers "does it fit" and nothing else — the
   * customer cannot tell which box is the sofa they chose. The picture goes on the front face
   * only: wrapped round all six it reads as a printed carton, and the front is the face a
   * piece of furniture is photographed from and the one it is turned towards the room.
   *
   * Textures are cached by URL, because four dining chairs are one photograph and four
   * downloads would be three too many.
   */
  private facesFor(item: LayoutItem, state: CollisionState | 'placeholder'): MeshStandardMaterial[] {
    const base = this.materials[state]

    const url = item.image_url

    if (url === null || state !== 'ok') {
      return [base, base, base, base, base, base]
    }

    let front = this.photographs.get(url)

    if (front === undefined) {
      // The loop only draws when something has changed, so a texture that arrives a moment
      // later has to say so — otherwise the photograph is downloaded, applied, and never
      // painted until the customer happens to move the camera.
      const texture = this.textures.load(url, () => this.onTextureLoaded())

      texture.colorSpace = SRGBColorSpace

      front = new MeshStandardMaterial({ map: texture, roughness: 0.8, metalness: 0 })

      this.photographs.set(url, front)
    }

    // BoxGeometry's material slots are +x, -x, +y, -y, +z, -z. The front of a piece faces
    // +z in its own space, which is the direction it looks when its rotation is zero.
    return [base, base, base, base, front, base]
  }

  /** Recolours a piece for its state, and outlines it when it is the one selected. */
  paint(group: Group, item: LayoutItem, state: CollisionState, selected: boolean): void {
    const body = group.getObjectByName('body')
    const edges = group.getObjectByName('edges')

    if (body instanceof Mesh) {
      body.material = this.facesFor(item, isMeasured(item) ? state : 'placeholder')
    }

    if (edges instanceof LineSegments) {
      edges.material = selected ? this.selectedEdgeMaterial : this.edgeMaterial
    }
  }

  /**
   * Frees a piece's geometry.
   *
   * Materials are shared and deliberately not touched here — disposing one would blank every
   * other piece in the same state.
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

    for (const material of this.photographs.values()) {
      material.map?.dispose()
      material.dispose()
    }

    this.photographs.clear()

    this.edgeMaterial.dispose()
    this.selectedEdgeMaterial.dispose()
  }
}
