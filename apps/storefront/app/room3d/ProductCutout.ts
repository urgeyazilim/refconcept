import { CanvasTexture, SRGBColorSpace, type Texture } from 'three'

/**
 * A product photograph with its studio background taken out.
 *
 * A catalogue photograph is a sofa on a white sweep. Pasted onto the face of a box it reads
 * as a box with a picture on it, which is what it is, and the room looks like a warehouse of
 * cartons. Cut out, the same photograph reads as a sofa standing in a room.
 *
 * The cut is a flood fill inwards from the border rather than "remove every white pixel",
 * and that difference is the whole reliability of it: a white cushion in the middle of a grey
 * sofa is not connected to the background, so it survives, while the sweep behind and between
 * the legs does not.
 *
 * Refuses rather than guesses. If the corners disagree with each other the photograph is of a
 * room rather than of a product on a sweep, and keying it would eat holes in the furniture —
 * so the caller gets null and draws its box as before. A worse picture is better than a sofa
 * with a bite out of it.
 */

/** How far a pixel may be from the sampled background and still count as background. */
const TOLERANCE = 32

/** How far the four corners may differ before the background is not a plain sweep. */
const CORNER_SPREAD = 26

/**
 * The longest edge the cut is done at.
 *
 * A catalogue photograph is two thousand pixels wide and a piece of furniture on screen is a
 * few hundred; the flood fill is per pixel, so the full size is four times the work for a
 * result nobody can see.
 */
const MAX_EDGE = 512

export interface Cutout {
  texture: Texture
  /** The photograph's dominant colour, for the volume the cut-out stands on. */
  colour: { r: number, g: number, b: number }
  /** Aspect of the trimmed product, so the plane can be sized to the thing rather than the frame. */
  aspect: number
}

/**
 * Loads a photograph and returns it without its background.
 *
 * Null when the image cannot be read — a photograph served without CORS headers taints the
 * canvas, and the browser refuses the pixels rather than the load, so this is a runtime answer
 * rather than something that can be decided in advance.
 */
export async function cutOut(url: string): Promise<Cutout | null> {
  const image = await load(url)

  if (image === null) {
    return null
  }

  const scale = Math.min(1, MAX_EDGE / Math.max(image.width, image.height))

  const width = Math.max(1, Math.round(image.width * scale))
  const height = Math.max(1, Math.round(image.height * scale))

  const canvas = document.createElement('canvas')

  canvas.width = width
  canvas.height = height

  const context = canvas.getContext('2d', { willReadFrequently: true })

  if (context === null) {
    return null
  }

  context.drawImage(image, 0, 0, width, height)

  let pixels: ImageData

  try {
    pixels = context.getImageData(0, 0, width, height)
  }
  catch {
    // Tainted: the photograph came from a host that does not allow reading it back.
    return null
  }

  const background = sampleBackground(pixels, width, height)

  if (background === null) {
    return null
  }

  keyOut(pixels, width, height, background)

  context.putImageData(pixels, 0, 0)

  /*
   * Trimmed to the product itself.
   *
   * A catalogue photograph is framed with air around the sofa, and that air is the difference
   * between a sofa standing in a room and a small sofa floating in the middle of a large
   * invisible rectangle. After the cut, the air is transparent and its extent is known
   * exactly, so the frame can be thrown away rather than guessed at.
   */
  const bounds = contentBounds(pixels, width, height)

  if (bounds === null) {
    return null
  }

  const trimmed = document.createElement('canvas')

  trimmed.width = bounds.width
  trimmed.height = bounds.height

  trimmed.getContext('2d')?.drawImage(
    canvas,
    bounds.x,
    bounds.y,
    bounds.width,
    bounds.height,
    0,
    0,
    bounds.width,
    bounds.height,
  )

  const texture = new CanvasTexture(trimmed)

  texture.colorSpace = SRGBColorSpace

  return {
    texture,
    colour: dominantColour(pixels),
    aspect: bounds.width / bounds.height,
  }
}

/**
 * The rectangle the product actually occupies, after the background has gone.
 *
 * Null when nothing is left — a photograph that keyed away entirely, which happens with a
 * white product on a white sweep. The caller draws its box instead, which is the right answer:
 * the alternative is an empty plane where a wardrobe should be.
 */
function contentBounds(
  pixels: ImageData,
  width: number,
  height: number,
): { x: number, y: number, width: number, height: number } | null {
  const data = pixels.data

  let top = height
  let left = width
  let right = -1
  let bottom = -1

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      if ((data[(y * width + x) * 4 + 3] ?? 0) < 8) {
        continue
      }

      if (x < left) {
        left = x
      }

      if (x > right) {
        right = x
      }

      if (y < top) {
        top = y
      }

      if (y > bottom) {
        bottom = y
      }
    }
  }

  if (right < left || bottom < top) {
    return null
  }

  return { x: left, y: top, width: right - left + 1, height: bottom - top + 1 }
}

function load(url: string): Promise<HTMLImageElement | null> {
  return new Promise((resolve) => {
    const image = new Image()

    // Without this the pixels are unreadable even when the server allows them.
    image.crossOrigin = 'anonymous'
    image.onload = () => resolve(image)
    image.onerror = () => resolve(null)
    image.src = url
  })
}

/**
 * The colour behind the product, if there is one colour behind the product.
 *
 * Taken from the four corners. They agreeing is the evidence that this is a studio sweep
 * rather than a photograph of a room, and it is the only evidence available without
 * understanding the picture.
 */
function sampleBackground(pixels: ImageData, width: number, height: number): [number, number, number] | null {
  const corners: Array<[number, number, number]> = [
    pixelAt(pixels, 0, 0),
    pixelAt(pixels, width - 1, 0),
    pixelAt(pixels, 0, height - 1),
    pixelAt(pixels, width - 1, height - 1),
  ]

  const average: [number, number, number] = [
    corners.reduce((sum, corner) => sum + corner[0], 0) / 4,
    corners.reduce((sum, corner) => sum + corner[1], 0) / 4,
    corners.reduce((sum, corner) => sum + corner[2], 0) / 4,
  ]

  for (const corner of corners) {
    if (distance(corner, average) > CORNER_SPREAD) {
      return null
    }
  }

  // A dark background is a photograph of a room, not a sweep. Keying it would remove the
  // shadow side of the furniture along with it.
  const brightness = (average[0] + average[1] + average[2]) / 3

  return brightness < 150 ? null : average
}

/**
 * Clears everything connected to the border that looks like the background.
 *
 * A queue rather than recursion: a 512-pixel image is a quarter of a million pixels and a
 * recursive fill hits the stack limit on the first white photograph it meets.
 */
function keyOut(pixels: ImageData, width: number, height: number, background: [number, number, number]): void {
  const data = pixels.data
  const seen = new Uint8Array(width * height)
  const queue: number[] = []

  const push = (x: number, y: number): void => {
    if (x < 0 || y < 0 || x >= width || y >= height) {
      return
    }

    const index = y * width + x

    if (seen[index] === 1) {
      return
    }

    seen[index] = 1

    const at = index * 4

    if (distance([data[at] ?? 0, data[at + 1] ?? 0, data[at + 2] ?? 0], background) > TOLERANCE) {
      return
    }

    data[at + 3] = 0
    queue.push(index)
  }

  for (let x = 0; x < width; x++) {
    push(x, 0)
    push(x, height - 1)
  }

  for (let y = 0; y < height; y++) {
    push(0, y)
    push(width - 1, y)
  }

  while (queue.length > 0) {
    const index = queue.pop() ?? 0

    const x = index % width
    const y = Math.floor(index / width)

    push(x - 1, y)
    push(x + 1, y)
    push(x, y - 1)
    push(x, y + 1)
  }
}

/**
 * The average colour of what is left after the cut.
 *
 * Used for the volume the cut-out stands on, so a grey sofa has a grey footprint and a walnut
 * sideboard a brown one — the block stops being a generic brown box and starts being a hint
 * about the thing standing there.
 */
function dominantColour(pixels: ImageData): { r: number, g: number, b: number } {
  const data = pixels.data

  let r = 0
  let g = 0
  let b = 0
  let counted = 0

  // Every eighth pixel. The answer is an average; sampling it costs a sixteenth of the work
  // and moves it by less than a shade.
  for (let index = 0; index < data.length; index += 32) {
    if ((data[index + 3] ?? 0) < 200) {
      continue
    }

    r += data[index] ?? 0
    g += data[index + 1] ?? 0
    b += data[index + 2] ?? 0
    counted++
  }

  if (counted === 0) {
    // The old generic brown, in the 0-1 the renderer wants.
    return { r: 0.66, g: 0.57, b: 0.48 }
  }

  return { r: r / counted / 255, g: g / counted / 255, b: b / counted / 255 }
}

function pixelAt(pixels: ImageData, x: number, y: number): [number, number, number] {
  const at = (y * pixels.width + x) * 4

  return [pixels.data[at] ?? 0, pixels.data[at + 1] ?? 0, pixels.data[at + 2] ?? 0]
}

function distance(a: [number, number, number], b: [number, number, number]): number {
  return Math.max(Math.abs(a[0] - b[0]), Math.abs(a[1] - b[1]), Math.abs(a[2] - b[2]))
}
