import {
  BoxGeometry,
  DoubleSide,
  ExtrudeGeometry,
  Group,
  Mesh,
  MeshStandardMaterial,
  Path,
  Shape,
  type BufferGeometry,
} from 'three'

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
  private readonly wallMaterial = new MeshStandardMaterial({
    color: 0xf2f0ec,
    roughness: 0.95,
    metalness: 0,
    // Both faces, because the camera goes inside the room and would otherwise see straight
    // through the wall behind it.
    side: DoubleSide,
  })

  private readonly floorMaterial = new MeshStandardMaterial({
    color: 0xd8c9b4,
    roughness: 0.8,
    metalness: 0,
  })

  private readonly ceilingMaterial = new MeshStandardMaterial({
    color: 0xfbfaf8,
    roughness: 1,
    metalness: 0,
    side: DoubleSide,
  })

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
    room.traverse(object => {
      if (object instanceof Mesh) {
        object.geometry.dispose()
      }
    })

    this.wallMaterial.dispose()
    this.floorMaterial.dispose()
    this.ceilingMaterial.dispose()
  }

  // --- surfaces --------------------------------------------------------------

  private floor(geometry: RoomGeometry): Mesh {
    const width = toUnits(geometry.width_mm)
    const length = toUnits(geometry.length_mm)
    const thickness = toUnits(RoomGeometryBuilder.WALL_THICKNESS_MM)

    const mesh = new Mesh(new BoxGeometry(width, thickness, length), this.floorMaterial)

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

    const mesh = new Mesh(wallGeometry, this.wallMaterial)
    mesh.name = `wall-${name}`
    mesh.receiveShadow = true

    this.placeWall(mesh, name, geometry, thickness)

    return mesh
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
