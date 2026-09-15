import {
  BoxGeometry,
  CylinderGeometry,
  Group,
  Mesh,
  SphereGeometry,
  type Material,
} from 'three'

/**
 * What a product looks like before it has a 3D model: a shape that is honestly its kind.
 *
 * Two things were tried and rejected before this. A box with the photograph on its front was
 * "a warehouse of cartons". The photograph cut out and stood up on its footprint was a flat
 * picture that turned to face whoever looked at it, and in the product owner's own screenshot
 * a round table on a red rug became a red plate — "ben bu şekilde istemedim". Neither was a
 * thing in a room.
 *
 * These are. A sofa is a seat with a back and two arms; a table is a top on legs; a wardrobe is
 * a cabinet with a plinth and a seam where the doors meet. Plain, solid, the product's own
 * colour, and at the SKU's exact size — so they sit in the room the way the real piece will,
 * cast a shadow like it, and never turn to face the camera. Not beautiful, and not pretending
 * to be: the real model replaces them the moment the catalogue has one.
 */
export type Shape =
  | 'seating'
  | 'chair'
  | 'table'
  | 'bed'
  | 'cabinet'
  | 'shelf'
  | 'lamp'
  | 'plant'
  | 'flat'
  | 'panel'
  | 'block'

/**
 * Category slug → shape.
 *
 * The slugs are the catalogue taxonomy's own. Anything not listed is a block, which is the
 * honest answer for a vase or a cushion at room scale.
 */
const SHAPES: Record<string, Shape> = {
  'kanepe': 'seating',
  'koltuk': 'seating',
  'oturma-grubu': 'seating',
  'puf': 'block',
  'sandalye': 'chair',
  'bar-taburesi': 'chair',
  'sehpa': 'table',
  'yemek-masasi': 'table',
  'masa-sandalye': 'table',
  'yatak': 'bed',
  'gardirop': 'cabinet',
  'komodin': 'cabinet',
  'konsol': 'cabinet',
  'tv-unitesi': 'cabinet',
  'mutfak-dolabi': 'cabinet',
  'banyo-dolabi': 'cabinet',
  'tezgah': 'cabinet',
  'depolama': 'cabinet',
  'lavabo': 'cabinet',
  'kitaplik': 'shelf',
  'lambader': 'lamp',
  'masa-lambasi': 'lamp',
  'bitki': 'plant',
  'hali': 'flat',
  'kilim': 'flat',
  'paspas': 'flat',
  'tablo': 'panel',
  'ayna': 'panel',
  'perde': 'panel',
  'duvar-aydinlatma': 'panel',
}

export function shapeFor(category: string | null): Shape {
  return category === null ? 'block' : (SHAPES[category] ?? 'block')
}

/**
 * Builds the shape, in scene units, with its base centred on the group's origin and its
 * front towards +z — the same convention every real model is normalised to.
 *
 * `body` is the product's colour; `accent` is a darker version of it for legs, poles and
 * plinths, so the silhouette reads even when the whole thing is one flat colour.
 */
export function buildShape(shape: Shape, width: number, height: number, depth: number, body: Material, accent: Material): Group {
  const group = new Group()

  const add = (geometry: BoxGeometry | CylinderGeometry | SphereGeometry, material: Material, x: number, y: number, z: number): void => {
    const mesh = new Mesh(geometry, material)

    mesh.position.set(x, y, z)
    mesh.castShadow = true
    mesh.receiveShadow = true

    group.add(mesh)
  }

  const box = (w: number, h: number, d: number): BoxGeometry => new BoxGeometry(w, h, d)

  switch (shape) {
    case 'seating': {
      // A seat, a back along the rear edge, an arm at each end.
      const seatHeight = height * 0.42
      const backDepth = depth * 0.22
      const armWidth = Math.min(width * 0.12, 0.25)

      add(box(width, seatHeight, depth), body, 0, seatHeight / 2, 0)
      add(box(width, height - seatHeight, backDepth), body, 0, seatHeight + (height - seatHeight) / 2, -depth / 2 + backDepth / 2)
      add(box(armWidth, height * 0.62, depth), body, -width / 2 + armWidth / 2, height * 0.31, 0)
      add(box(armWidth, height * 0.62, depth), body, width / 2 - armWidth / 2, height * 0.31, 0)
      break
    }

    case 'chair': {
      const seatTop = height * 0.45
      const seatThickness = Math.min(height * 0.06, 0.05)
      const backDepth = Math.min(depth * 0.08, 0.04)

      add(box(width, seatThickness, depth), body, 0, seatTop - seatThickness / 2, 0)
      add(box(width, height - seatTop, backDepth), body, 0, seatTop + (height - seatTop) / 2, -depth / 2 + backDepth / 2)
      legs(add, accent, width, depth, seatTop - seatThickness, 0.02)
      break
    }

    case 'table': {
      const top = Math.min(height * 0.08, 0.04)
      const round = Math.abs(width - depth) < Math.max(width, depth) * 0.12

      if (round) {
        // One pedestal under a round top: what a round table almost always stands on.
        const radius = Math.min(width, depth) / 2

        add(new CylinderGeometry(radius, radius, top, 32), body, 0, height - top / 2, 0)
        add(new CylinderGeometry(radius * 0.12, radius * 0.16, height - top, 16), accent, 0, (height - top) / 2, 0)
        add(new CylinderGeometry(radius * 0.45, radius * 0.5, 0.02, 32), accent, 0, 0.01, 0)
      }
      else {
        add(box(width, top, depth), body, 0, height - top / 2, 0)
        legs(add, accent, width, depth, height - top, Math.min(0.03, width * 0.04))
      }
      break
    }

    case 'bed': {
      // The base, a slightly narrower mattress on it, and the headboard along the rear edge.
      const baseHeight = height * 0.42
      const mattressHeight = height * 0.16
      const headboardDepth = Math.min(depth * 0.04, 0.06)

      add(box(width, baseHeight, depth - headboardDepth), body, 0, baseHeight / 2, headboardDepth / 2)
      add(box(width * 0.96, mattressHeight, (depth - headboardDepth) * 0.94), body, 0, baseHeight + mattressHeight / 2, headboardDepth / 2)
      add(box(width, height, headboardDepth), accent, 0, height / 2, -depth / 2 + headboardDepth / 2)
      break
    }

    case 'cabinet': {
      // A carcass on a plinth, with a seam where two doors would meet.
      const plinth = Math.min(height * 0.06, 0.08)
      const seam = 0.006

      add(box(width, height - plinth, depth), body, 0, plinth + (height - plinth) / 2, 0)
      add(box(width * 0.96, plinth, depth * 0.9), accent, 0, plinth / 2, 0)

      if (width > 0.9) {
        add(box(seam, height - plinth - 0.02, 0.004), accent, 0, plinth + (height - plinth) / 2, depth / 2)
      }
      break
    }

    case 'shelf': {
      // Sides, back, and shelves every 35 cm or so.
      const panel = 0.025
      const shelves = Math.max(2, Math.round(height / 0.35))

      add(box(panel, height, depth), body, -width / 2 + panel / 2, height / 2, 0)
      add(box(panel, height, depth), body, width / 2 - panel / 2, height / 2, 0)
      add(box(width, height, panel), body, 0, height / 2, -depth / 2 + panel / 2)

      for (let index = 0; index <= shelves; index++) {
        const y = Math.min(height - panel / 2, index * (height / shelves) + panel / 2)

        add(box(width - panel * 2, panel, depth - panel), body, 0, y, panel / 2)
      }
      break
    }

    case 'lamp': {
      // Base, pole, shade. The shade is the product's colour; the rest is hardware.
      const radius = Math.min(width, depth) / 2
      const shadeHeight = height * 0.28

      add(new CylinderGeometry(radius * 0.5, radius * 0.5, 0.02, 24), accent, 0, 0.01, 0)
      add(new CylinderGeometry(0.012, 0.012, height - shadeHeight, 12), accent, 0, (height - shadeHeight) / 2, 0)
      add(new CylinderGeometry(radius * 0.7, radius, shadeHeight, 24, 1, true), body, 0, height - shadeHeight / 2, 0)
      break
    }

    case 'plant': {
      const radius = Math.min(width, depth) / 2
      const pot = Math.min(height * 0.35, radius * 1.4)

      add(new CylinderGeometry(radius * 0.7, radius * 0.55, pot, 20), accent, 0, pot / 2, 0)
      add(new SphereGeometry(radius, 12, 10), body, 0, Math.max(pot + radius * 0.8, height - radius), 0)
      break
    }

    case 'flat':
      add(box(width, Math.min(height, 0.015), depth), body, 0, Math.min(height, 0.015) / 2, 0)
      break

    case 'panel':
      add(box(width, height, Math.min(depth, 0.03)), body, 0, height / 2, 0)
      break

    case 'block':
      add(box(width, height, depth), body, 0, height / 2, 0)
      break
  }

  return group
}

/** Four legs, one in each corner, inset by their own thickness. */
function legs(
  add: (geometry: BoxGeometry, material: Material, x: number, y: number, z: number) => void,
  material: Material,
  width: number,
  depth: number,
  height: number,
  thickness: number,
): void {
  const inset = Math.max(thickness, Math.min(width, depth) * 0.06)

  for (const sx of [-1, 1]) {
    for (const sz of [-1, 1]) {
      add(
        new BoxGeometry(thickness, height, thickness),
        material,
        sx * (width / 2 - inset),
        height / 2,
        sz * (depth / 2 - inset),
      )
    }
  }
}
