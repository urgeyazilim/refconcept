import {
  BoxGeometry,
  BufferGeometry,
  ExtrudeGeometry,
  Group,
  Line,
  LineBasicMaterial,
  Mesh,
  Path,
  PlaneGeometry,
  Shape,
  Vector3,
  type Object3D,
} from 'three'

import {
  PLANK_TILE_M,
  PLASTER_TILE_M,
  ceilingMaterial,
  disposeRoomTextures,
  doorLeafMaterial,
  floorMaterial,
  glassMaterial,
  skyMaterial,
  trimMaterial,
  wallMaterial,
} from './RoomMaterials'
import { type RoomGeometry, type RoomOpening, type WallName, toUnits } from './types'

/**
 * Turns confirmed measurements into a room somebody can stand in.
 *
 * Four walls, a floor and a ceiling, with real holes where the doors and windows are. The
 * holes matter more than they look: a window painted on a wall as a blue rectangle reads as
 * a poster, and the moment a customer orbits the camera the illusion is gone. Cut through
 * the geometry and the daylight behind it does the work for free.
 *
 * Built with extruded shapes rather than boxes for exactly that reason. A wall is a 2D
 * outline with rectangular paths punched out of it, extruded to the wall's thickness — which
 * is how a real opening is made, and costs one geometry per wall rather than the five a
 * boolean subtraction would need.
 *
 * Everything arrives in millimetres and is converted once, here. The origin is the corner
 * where the north and west walls meet, matching the API: x runs along the width, z along the
 * length, y upwards.
 */
export class RoomGeometryBuilder {
  /**
   * How thick a wall is drawn, in millimetres.
   *
   * Not a measurement of the customer's actual walls — we do not know those and they do not
   * matter to a layout. It exists so a wall has two faces: a plane has none from behind, and
   * a room built from planes turns inside out the moment the camera passes through one.
   */
  private static readonly WALL_THICKNESS_MM = 100

  /**
   * Materials are built once and shared.
   *
   * A room is six meshes and a scene may hold a dozen rooms over a session; a new material
   * per mesh is a new shader program per mesh, which is the difference between a scene that
   * opens instantly and one that stutters for a second first.
   */
  /** Skirting board: the strip along the bottom of every wall, in millimetres. */
  private static readonly SKIRTING_HEIGHT_MM = 110

  private static readonly SKIRTING_DEPTH_MM = 15

  /** Door and window frames: the visible face of the casing, in millimetres. */
  private static readonly FRAME_WIDTH_MM = 60

  private static readonly FRAME_DEPTH_MM = 20

  /** How far a door stands open into the room. Enough to read as a door; not in the way. */
  private static readonly DOOR_OPEN_DEG = 30

  private readonly wallMaterial = wallMaterial()

  private readonly floorMaterial = floorMaterial()

  private readonly ceilingMaterial = ceilingMaterial()

  private readonly trimMaterial = trimMaterial()

  private readonly leafMaterial = doorLeafMaterial()

  private readonly glassMaterial = glassMaterial()

  private readonly skyMaterial = skyMaterial()

  private readonly swingMaterial = new LineBasicMaterial({ color: 0x9a8f80, transparent: true, opacity: 0.7 })

  /**
   * Builds the shell.
   *
   * Returned as a group so the whole room can be hidden, swapped or disposed in one move —
   * which the view switcher does every time somebody asks for the plan instead of the
   * perspective.
   */
  build(geometry: RoomGeometry, openings: RoomOpening[]): Group {
    const room = new Group()
    room.name = 'room'

    room.add(this.floor(geometry))
    room.add(this.ceiling(geometry))

    for (const wall of ['north', 'south', 'east', 'west'] as WallName[]) {
      room.add(this.wall(wall, geometry, openings.filter(opening => opening.wall === wall)))
    }

    return room
  }

  /** Frees every geometry and material the group owns. */
  dispose(room: Group): void {
    room.traverse((object) => {
      if (object instanceof Mesh || object instanceof Line) {
        object.geometry.dispose()
      }
    })

    this.wallMaterial.dispose()
    this.floorMaterial.dispose()
    this.ceilingMaterial.dispose()
    this.trimMaterial.dispose()
    this.leafMaterial.dispose()
    this.glassMaterial.dispose()
    this.skyMaterial.dispose()
    this.swingMaterial.dispose()

    disposeRoomTextures()
  }

  // --- surfaces --------------------------------------------------------------

  private floor(geometry: RoomGeometry): Mesh {
    const width = toUnits(geometry.width_mm)
    const length = toUnits(geometry.length_mm)
    const thickness = toUnits(RoomGeometryBuilder.WALL_THICKNESS_MM)

    const mesh = new Mesh(new BoxGeometry(width, thickness, length), this.floorMaterial)

    // The boards repeat at their real size, however big the room is: a plank tile stretched
    // to fit a five-metre floor is a floor of five-metre planks.
    this.floorMaterial.map?.repeat.set(width / PLANK_TILE_M, length / PLANK_TILE_M)

    // Its top face sits at y = 0, so a sofa at position_y_mm = 0 stands *on* the floor
    // rather than half inside it.
    mesh.position.set(width / 2, -thickness / 2, length / 2)
    mesh.receiveShadow = true
    mesh.name = 'floor'

    return mesh
  }

  private ceiling(geometry: RoomGeometry): Mesh {
    const width = toUnits(geometry.width_mm)
    const length = toUnits(geometry.length_mm)
    const height = toUnits(geometry.height_mm)
    const thickness = toUnits(RoomGeometryBuilder.WALL_THICKNESS_MM)

    const mesh = new Mesh(new BoxGeometry(width, thickness, length), this.ceilingMaterial)

    mesh.position.set(width / 2, height + thickness / 2, length / 2)
    mesh.name = 'ceiling'

    return mesh
  }

  /**
   * One wall, with its openings cut out of it.
   *
   * Each wall is drawn flat in its own 2D space — horizontal axis along the wall, vertical
   * axis up — then extruded and moved into place. Working in the wall's own coordinates is
   * what keeps the opening arithmetic readable: an offset is a distance along the outline,
   * not a projection onto a world axis that changes meaning per wall.
   */
  private wall(name: WallName, geometry: RoomGeometry, openings: RoomOpening[]): Mesh {
    const height = toUnits(geometry.height_mm)
    const thickness = toUnits(RoomGeometryBuilder.WALL_THICKNESS_MM)

    // North and south run the width of the room; east and west run its length.
    const spanMm = name === 'north' || name === 'south' ? geometry.width_mm : geometry.length_mm
    const span = toUnits(spanMm)

    const outline = new Shape()
    outline.moveTo(0, 0)
    outline.lineTo(span, 0)
    outline.lineTo(span, height)
    outline.lineTo(0, height)
    outline.closePath()

    for (const opening of openings) {
      const hole = this.holeFor(opening, spanMm, geometry.height_mm)

      if (hole !== null) {
        outline.holes.push(hole)
      }
    }

    const wallGeometry: BufferGeometry = new ExtrudeGeometry(outline, {
      depth: thickness,
      bevelEnabled: false,
    })

    // Extruded geometry carries UVs in scene units, so one plaster tile is one metre of wall.
    this.wallMaterial.map?.repeat.set(1 / PLASTER_TILE_M, 1 / PLASTER_TILE_M)

    const mesh = new Mesh(wallGeometry, this.wallMaterial)
    mesh.name = `wall-${name}`
    mesh.receiveShadow = true

    /*
     * Everything that belongs to the wall is a child of it: the skirting, the door and
     * window casings, the glass, the daylight behind the glass. They are built in the wall's
     * own 2D space and go wherever the wall goes — and when the wall is hidden because the
     * camera is outside it, they are hidden with it, rather than left floating in the air.
     */
    const inward = name === 'north' || name === 'east' ? 1 : -1
    const innerZ = inward === 1 ? thickness : 0

    mesh.add(...this.skirting(spanMm, openings, inward, innerZ))

    for (const opening of openings) {
      mesh.add(...this.fixture(opening, spanMm, geometry.height_mm, inward, innerZ, thickness))
    }

    this.placeWall(mesh, name, geometry, thickness)

    return mesh
  }

  /**
   * The skirting board, in pieces, leaving the doorways out.
   *
   * A strip along the foot of a wall is the single cheapest thing that makes a box read as a
   * room: it says where the floor ends and gives the eye a line to judge distance by.
   */
  private skirting(spanMm: number, openings: RoomOpening[], inward: 1 | -1, innerZ: number): Mesh[] {
    const height = toUnits(RoomGeometryBuilder.SKIRTING_HEIGHT_MM)
    const depth = toUnits(RoomGeometryBuilder.SKIRTING_DEPTH_MM)

    // Doorways break the strip; windows do not.
    const gaps = openings
      .filter(opening => (opening.sill_height_mm ?? 0) === 0 && opening.offset_mm !== null && opening.width_mm !== null)
      .map(opening => ({ from: opening.offset_mm!, to: opening.offset_mm! + opening.width_mm! }))
      .sort((a, b) => a.from - b.from)

    const pieces: Mesh[] = []
    let cursor = 0

    const piece = (fromMm: number, toMm: number): void => {
      if (toMm - fromMm < 20) {
        return
      }

      const length = toUnits(toMm - fromMm)
      const mesh = new Mesh(new BoxGeometry(length, height, depth), this.trimMaterial)

      mesh.position.set(toUnits(fromMm) + length / 2, height / 2, innerZ + inward * depth / 2)
      mesh.castShadow = false
      mesh.receiveShadow = true
      pieces.push(mesh)
    }

    for (const gap of gaps) {
      piece(cursor, Math.max(cursor, gap.from))
      cursor = Math.max(cursor, gap.to)
    }

    piece(cursor, spanMm)

    return pieces
  }

  /**
   * What fills an opening: a door with its frame and leaf, or a window with its frame, sill,
   * glass and the daylight behind it.
   *
   * Without these a hole in a wall is a hole in a wall. The storyboard shows a room with a
   * window and a door in it, and a customer judging whether a wardrobe clears the door needs
   * to see a door — where it hangs, which way it swings, how far it comes into the room.
   */
  private fixture(
    opening: RoomOpening,
    spanMm: number,
    wallHeightMm: number,
    inward: 1 | -1,
    innerZ: number,
    thickness: number,
  ): Object3D[] {
    const { offset_mm: offset, width_mm: width, height_mm: height } = opening

    if (offset === null || width === null || height === null || width <= 0 || height <= 0) {
      return []
    }

    const sill = opening.sill_height_mm ?? 0
    const left = Math.max(0, Math.min(offset, spanMm - width))
    const bottom = Math.max(0, Math.min(sill, wallHeightMm - height))

    const x1 = toUnits(left)
    const w = toUnits(width)
    const y1 = toUnits(bottom)
    const h = toUnits(height)

    const frameWidth = toUnits(RoomGeometryBuilder.FRAME_WIDTH_MM)
    const frameDepth = toUnits(RoomGeometryBuilder.FRAME_DEPTH_MM)

    const parts: Object3D[] = []

    // The casing: two jambs and a head, standing a little proud of the wall on the room side.
    const casingZ = innerZ + inward * frameDepth / 2

    for (const [cx, cy, cw, ch] of [
      [x1 - frameWidth / 2, y1 + h / 2, frameWidth, h + frameWidth],
      [x1 + w + frameWidth / 2, y1 + h / 2, frameWidth, h + frameWidth],
      [x1 + w / 2, y1 + h + frameWidth / 2, w + frameWidth * 2, frameWidth],
    ] as const) {
      const jamb = new Mesh(new BoxGeometry(cw, ch, frameDepth), this.trimMaterial)

      jamb.position.set(cx, cy, casingZ)
      jamb.castShadow = true
      parts.push(jamb)
    }

    const isDoor = sill === 0

    if (isDoor) {
      /*
       * The leaf, hung on the left jamb and standing open into the room.
       *
       * Open rather than shut: a shut door is a panel filling a hole, and the customer cannot
       * tell it from a cupboard. Open, it says which way it swings and how much floor it
       * needs — which is the clearance the collision rules keep for it.
       */
      const leafThickness = 0.04
      const hinge = new Group()

      hinge.position.set(x1, 0, innerZ)
      hinge.rotation.y = -inward * (RoomGeometryBuilder.DOOR_OPEN_DEG * Math.PI) / 180

      const leaf = new Mesh(
        new BoxGeometry(w - 0.02, h - 0.02, leafThickness),
        opening.type === 'balcony_door' ? this.glassMaterial : this.leafMaterial,
      )

      leaf.position.set((w - 0.02) / 2 + 0.01, (h - 0.02) / 2 + 0.01, inward * leafThickness / 2)
      leaf.castShadow = true
      hinge.add(leaf)
      parts.push(hinge)

      // The swing, drawn on the floor: a quarter arc from the hinge, the door's width across.
      const points: Vector3[] = []

      for (let step = 0; step <= 16; step++) {
        const angle = (step / 16) * (Math.PI / 2)

        points.push(new Vector3(x1 + w * Math.cos(angle), 0.004, innerZ + inward * w * Math.sin(angle)))
      }

      parts.push(new Line(new BufferGeometry().setFromPoints(points), this.swingMaterial))
    }
    else {
      // The sill: a board along the bottom of the window, proud of the wall.
      const sillDepth = 0.12
      const sillBoard = new Mesh(new BoxGeometry(w + frameWidth * 2, 0.04, sillDepth), this.trimMaterial)

      sillBoard.position.set(x1 + w / 2, y1 - 0.02, innerZ + inward * sillDepth / 2)
      sillBoard.castShadow = true
      parts.push(sillBoard)

      // The glass, in the middle of the wall's thickness, with a mullion down the centre.
      const glassZ = innerZ - inward * thickness / 2

      const glass = new Mesh(new PlaneGeometry(w, h), this.glassMaterial)

      glass.position.set(x1 + w / 2, y1 + h / 2, glassZ)
      parts.push(glass)

      const mullion = new Mesh(new BoxGeometry(0.04, h, 0.05), this.trimMaterial)

      mullion.position.set(x1 + w / 2, y1 + h / 2, glassZ)
      parts.push(mullion)

      /*
       * Daylight: a bright sheet just outside, seen only through the hole.
       *
       * The size of the whole wall rather than of the window, so that from outside the room
       * the wall hides all of it — a sheet a little larger than the window stuck out above
       * the wall's top edge and floated there in the doll's-house view.
       */
      // Inset a little from the wall's edges, or a sliver shows past the corner from outside.
      const inset = 0.2
      const sky = new Mesh(new PlaneGeometry(toUnits(spanMm) - inset * 2, toUnits(wallHeightMm) - inset * 2), this.skyMaterial)

      sky.position.set(toUnits(spanMm) / 2, toUnits(wallHeightMm) / 2, innerZ - inward * (thickness + 0.35))
      parts.push(sky)
    }

    return parts
  }

  /**
   * The rectangle to punch out for one opening, in the wall's own 2D space.
   *
   * Returns null for anything not fully measured. A door recorded without a width cannot be
   * cut, and guessing one would put a hole of invented size in somebody's wall — which looks
   * exactly like a bug in the model that detected it.
   */
  private holeFor(opening: RoomOpening, spanMm: number, wallHeightMm: number): Path | null {
    const { offset_mm: offset, width_mm: width, height_mm: height } = opening

    if (offset === null || width === null || height === null || width <= 0 || height <= 0) {
      return null
    }

    // A sill of zero is a doorway; anything else is a window and starts partway up.
    const sill = opening.sill_height_mm ?? 0

    /*
     * Clamped to the wall it is on.
     *
     * An estimate that puts a 1.8 m window 4.2 m along a 4.85 m wall overruns the corner by
     * fifteen centimetres, and an opening that crosses a wall's edge produces a hole the
     * extruder cannot close — the wall renders inside out. Clamping keeps a slightly wrong
     * estimate looking slightly wrong rather than catastrophically broken.
     */
    const left = Math.max(0, Math.min(offset, spanMm - width))
    const bottom = Math.max(0, Math.min(sill, wallHeightMm - height))

    const x1 = toUnits(left)
    const x2 = toUnits(left + width)
    const y1 = toUnits(bottom)
    const y2 = toUnits(bottom + height)

    const hole = new Path()
    hole.moveTo(x1, y1)
    hole.lineTo(x2, y1)
    hole.lineTo(x2, y2)
    hole.lineTo(x1, y2)
    hole.closePath()

    return hole
  }

  /**
   * Moves a flat wall into its place around the room.
   *
   * Each wall is built lying in the xy plane and extruded towards +z, so placing it is one
   * rotation about the vertical axis and one translation.
   *
   * **Only two orientations, on purpose.** North and south are not rotated at all and differ
   * only in z; east and west are both turned the same quarter turn and differ only in x. The
   * tempting version turns each wall to "face into the room", and it reverses the direction
   * an offset runs along two of them — so a window 720 mm along the south wall lands 720 mm
   * from the wrong corner, which looks like a mirrored model rather than an arithmetic bug
   * and is very hard to see in a screenshot.
   *
   * With these four, an offset always runs the same way as the axis it lies on: x for north
   * and south, z for east and west. That is exactly what the server assumes, and the two
   * agreeing matters more than either being "natural".
   *
   * Both faces are drawn, so a wall having no preferred side costs nothing.
   */
  private placeWall(mesh: Mesh, name: WallName, geometry: RoomGeometry, thickness: number): void {
    const width = toUnits(geometry.width_mm)
    const length = toUnits(geometry.length_mm)

    switch (name) {
      case 'north':
        // Extruded towards +z, so pulling it back by its thickness leaves its inner face at
        // z = 0 — the room's edge — rather than a wall's width inside the room.
        mesh.position.set(0, 0, -thickness)
        break

      case 'south':
        mesh.position.set(0, 0, length)
        break

      case 'west':
        // A quarter turn maps the wall's own horizontal axis onto z and its extrusion onto
        // -x, which puts the solid part outside the room and the inner face at x = 0.
        mesh.rotation.y = -Math.PI / 2
        mesh.position.set(0, 0, 0)
        break

      case 'east':
        mesh.rotation.y = -Math.PI / 2
        mesh.position.set(width + thickness, 0, 0)
        break
    }
  }
}
