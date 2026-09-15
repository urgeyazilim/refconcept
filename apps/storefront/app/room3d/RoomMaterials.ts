import {
  CanvasTexture,
  Color,
  DoubleSide,
  MeshBasicMaterial,
  MeshStandardMaterial,
  RepeatWrapping,
  SRGBColorSpace,
} from 'three'

import type { FloorMaterial } from './types'

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

/** How much floor one repeat of each floor texture covers, in metres. */
export const FLOOR_TILE_M: Record<FloorMaterial, number> = {
  wood: 1.2,
  tile: 1.2,
  carpet: 1.0,
}

/** How much wall one repeat of the plaster texture covers, in metres. */
export const PLASTER_TILE_M = 1.0

let planks: CanvasTexture | null = null
let tiles: CanvasTexture | null = null
let carpet: CanvasTexture | null = null
let plaster: CanvasTexture | null = null

function blankCanvas(size: number): [HTMLCanvasElement, CanvasRenderingContext2D] {
  const canvas = document.createElement('canvas')

  canvas.width = size
  canvas.height = size

  const context = canvas.getContext('2d')

  if (context === null) {
    throw new Error('2D canvas is not available')
  }

  return [canvas, context]
}

function repeating(canvas: HTMLCanvasElement): CanvasTexture {
  const texture = new CanvasTexture(canvas)

  texture.wrapS = RepeatWrapping
  texture.wrapT = RepeatWrapping
  texture.colorSpace = SRGBColorSpace
  texture.anisotropy = 4

  return texture
}

/**
 * Porcelain tiles, four to a tile of texture — 60 cm each — with a grout line between.
 *
 * Each tile its own barely different shade and a faint mottle, which is what stops a tiled
 * floor reading as graph paper.
 */
function tileTexture(): CanvasTexture {
  if (tiles !== null) {
    return tiles
  }

  const size = 1024
  const [canvas, context] = blankCanvas(size)
  const random = seeded(19)
  const across = 2
  const cell = size / across

  context.fillStyle = '#b9b3aa'
  context.fillRect(0, 0, size, size)

  for (let row = 0; row < across; row++) {
    for (let column = 0; column < across; column++) {
      const tone = 0.96 + random() * 0.06
      const base = new Color('#e4e0d8').multiplyScalar(tone)

      context.fillStyle = `#${base.getHexString()}`
      context.fillRect(column * cell + 3, row * cell + 3, cell - 6, cell - 6)

      for (let speck = 0; speck < 400; speck++) {
        const shade = random() > 0.5 ? 255 : 0

        context.fillStyle = `rgba(${shade}, ${shade}, ${shade}, ${0.015 + random() * 0.03})`
        context.fillRect(column * cell + 3 + random() * (cell - 6), row * cell + 3 + random() * (cell - 6), 2 + random() * 6, 2 + random() * 6)
      }
    }
  }

  tiles = repeating(canvas)

  return tiles
}

/** Carpet: a warm grey with a dense, fine, even nap. */
function carpetTexture(): CanvasTexture {
  if (carpet !== null) {
    return carpet
  }

  const size = 512
  const [canvas, context] = blankCanvas(size)
  const random = seeded(23)

  context.fillStyle = '#b8ad9d'
  context.fillRect(0, 0, size, size)

  for (let fibre = 0; fibre < 40_000; fibre++) {
    const shade = random() > 0.5 ? 255 : 0

    context.fillStyle = `rgba(${shade}, ${shade}, ${shade}, ${0.03 + random() * 0.05})`
    context.fillRect(random() * size, random() * size, 1, 1 + random() * 2)
  }

  carpet = repeating(canvas)

  return carpet
}

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
  const [canvas, context] = blankCanvas(size)

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

  planks = repeating(canvas)

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
  const [canvas, context] = blankCanvas(size)

  context.fillStyle = '#f1efea'
  context.fillRect(0, 0, size, size)

  const random = seeded(11)

  for (let dot = 0; dot < 6000; dot++) {
    const shade = random() > 0.5 ? 255 : 0

    context.fillStyle = `rgba(${shade}, ${shade}, ${shade}, ${0.02 + random() * 0.04})`
    context.fillRect(random() * size, random() * size, 1 + random() * 2, 1 + random() * 2)
  }

  plaster = repeating(canvas)

  return plaster
}

/** The floor, in whichever of the three it is made of. */
export function floorMaterial(kind: FloorMaterial = 'wood'): MeshStandardMaterial {
  switch (kind) {
    case 'tile':
      return new MeshStandardMaterial({ map: tileTexture(), roughness: 0.35, metalness: 0 })
    case 'carpet':
      return new MeshStandardMaterial({ map: carpetTexture(), roughness: 1, metalness: 0 })
    default:
      return new MeshStandardMaterial({ map: plankTexture(), roughness: 0.55, metalness: 0 })
  }
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
  tiles?.dispose()
  carpet?.dispose()
  plaster?.dispose()
  planks = null
  tiles = null
  carpet = null
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
