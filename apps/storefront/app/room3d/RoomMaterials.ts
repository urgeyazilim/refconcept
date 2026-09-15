import {
  CanvasTexture,
  Color,
  DoubleSide,
  MeshBasicMaterial,
  MeshStandardMaterial,
  RepeatWrapping,
  SRGBColorSpace,
} from 'three'

/**
 * What the room is made of.
 *
 * Painted here, on a canvas, rather than loaded from image files: a plank floor and a plaster
 * wall are a few hundred lines of drawing, and a texture that ships with the code cannot 404,
 * cannot be blocked by a content policy and cannot arrive a second after the room did. Two
 * materials, at a size that reads as wood and plaster from across a room and costs a
 * megabyte of GPU memory between them.
 *
 * The first room was six flat colours, and the product owner's word for it was "diagram".
 */

/** How much floor one repeat of the plank texture covers, in metres. */
export const PLANK_TILE_M = 1.2

/** How much wall one repeat of the plaster texture covers, in metres. */
export const PLASTER_TILE_M = 1.0

let planks: CanvasTexture | null = null
let plaster: CanvasTexture | null = null

/**
 * Oak boards, eight to a tile, staggered the way a floor is laid.
 *
 * Each board its own shade — never more than a few percent apart, which is what makes it
 * read as one floor rather than a chessboard — with a handful of faint grain lines and a
 * dark seam between boards. Deterministic: the same floor every time the page opens.
 */
function plankTexture(): CanvasTexture {
  if (planks !== null) {
    return planks
  }

  const size = 1024
  const canvas = document.createElement('canvas')

  canvas.width = size
  canvas.height = size

  const context = canvas.getContext('2d')

  if (context === null) {
    throw new Error('2D canvas is not available')
  }

  const random = seeded(7)
  const rows = 8
  const rowHeight = size / rows

  context.fillStyle = '#b8955f'
  context.fillRect(0, 0, size, size)

  for (let row = 0; row < rows; row++) {
    // Boards in a row run the whole tile; the joints are staggered from row to row.
    let x = -Math.floor(random() * size * 0.6)

    while (x < size) {
      const length = size * (0.45 + random() * 0.4)
      const tone = 0.9 + random() * 0.2

      const base = new Color('#c4a06a').multiplyScalar(tone)

      context.fillStyle = `#${base.getHexString()}`
      context.fillRect(x, row * rowHeight, length, rowHeight)

      // Grain: long, faint, slightly wavy lines along the board.
      context.strokeStyle = `rgba(90, 60, 30, ${0.06 + random() * 0.08})`
      context.lineWidth = 1

      for (let line = 0; line < 6; line++) {
        const y = row * rowHeight + rowHeight * (0.1 + random() * 0.8)

        context.beginPath()
        context.moveTo(x, y)

        for (let step = 0; step <= length; step += 64) {
          context.lineTo(x + step, y + Math.sin(step / 90 + line) * 1.5)
        }

        context.stroke()
      }

      // The joint at the end of the board.
      context.fillStyle = 'rgba(60, 40, 20, 0.45)'
      context.fillRect(x + length - 1.5, row * rowHeight, 1.5, rowHeight)

      x += length
    }

    // The seam between rows.
    context.fillStyle = 'rgba(60, 40, 20, 0.5)'
    context.fillRect(0, row * rowHeight - 1, size, 2)
  }

  planks = new CanvasTexture(canvas)
  planks.wrapS = RepeatWrapping
  planks.wrapT = RepeatWrapping
  planks.colorSpace = SRGBColorSpace
  planks.anisotropy = 4

  return planks
}

/**
 * Plaster: a flat colour with a little noise in it.
 *
 * The noise is what stops a wall reading as a flat fill. It is too fine to see as texture
 * and just enough to let the eye believe the surface is there.
 */
function plasterTexture(): CanvasTexture {
  if (plaster !== null) {
    return plaster
  }

  const size = 512
  const canvas = document.createElement('canvas')

  canvas.width = size
  canvas.height = size

  const context = canvas.getContext('2d')

  if (context === null) {
    throw new Error('2D canvas is not available')
  }

  context.fillStyle = '#f1efea'
  context.fillRect(0, 0, size, size)

  const random = seeded(11)

  for (let dot = 0; dot < 6000; dot++) {
    const shade = random() > 0.5 ? 255 : 0

    context.fillStyle = `rgba(${shade}, ${shade}, ${shade}, ${0.02 + random() * 0.04})`
    context.fillRect(random() * size, random() * size, 1 + random() * 2, 1 + random() * 2)
  }

  plaster = new CanvasTexture(canvas)
  plaster.wrapS = RepeatWrapping
  plaster.wrapT = RepeatWrapping
  plaster.colorSpace = SRGBColorSpace

  return plaster
}

/** A material per surface, sharing the two textures. */
export function floorMaterial(): MeshStandardMaterial {
  return new MeshStandardMaterial({ map: plankTexture(), roughness: 0.55, metalness: 0 })
}

export function wallMaterial(): MeshStandardMaterial {
  return new MeshStandardMaterial({ map: plasterTexture(), roughness: 0.95, metalness: 0, side: DoubleSide })
}

export function ceilingMaterial(): MeshStandardMaterial {
  return new MeshStandardMaterial({ color: 0xfbfaf8, roughness: 1, metalness: 0, side: DoubleSide })
}

/** Painted timber: skirting, door and window frames. */
export function trimMaterial(): MeshStandardMaterial {
  return new MeshStandardMaterial({ color: 0xf7f5f0, roughness: 0.6, metalness: 0 })
}

export function doorLeafMaterial(): MeshStandardMaterial {
  return new MeshStandardMaterial({ color: 0xd9c3a3, roughness: 0.6, metalness: 0 })
}

/** Glass: mostly not there, faintly blue, both faces. */
export function glassMaterial(): MeshStandardMaterial {
  return new MeshStandardMaterial({
    color: 0xcfe3f2,
    roughness: 0.05,
    metalness: 0,
    transparent: true,
    opacity: 0.28,
    side: DoubleSide,
    depthWrite: false,
  })
}

/** Daylight, seen through a window: a bright, unlit sheet just outside it. */
export function skyMaterial(): MeshBasicMaterial {
  return new MeshBasicMaterial({ color: 0xe6eef6, side: DoubleSide })
}

/** Release the shared textures. Called once when the last scene closes. */
export function disposeRoomTextures(): void {
  planks?.dispose()
  plaster?.dispose()
  planks = null
  plaster = null
}

/** A small deterministic generator, so the floor is the same floor every time. */
function seeded(seed: number): () => number {
  let state = seed * 9301 + 49297

  return () => {
    state = (state * 9301 + 49297) % 233280

    return state / 233280
  }
}
