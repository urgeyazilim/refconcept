import { readdirSync, statSync, unlinkSync } from 'node:fs'
import { join, parse, resolve } from 'node:path'
import sharp from 'sharp'

/**
 * Converts generated PNGs into web-ready WebP at sensible dimensions.
 *
 * The generator returns large lossless PNGs — around 700 KB each, six megabytes for
 * the set. Shipping those would undo the point of a "calm, fast" storefront, so each
 * image is resized to the largest size it is actually displayed at and re-encoded.
 *
 *   node scripts/optimize-imagery.mjs
 *   node scripts/optimize-imagery.mjs --keep-png
 */

const DIR = 'apps/storefront/public/images'

/** Widths chosen from how each image is used in the layout, not from the source size. */
const WIDTHS = {
  'hero-living-room': 1600,
  'before-room': 900,
  'room-': 900,
  'product-': 600,
}

function widthFor(name) {
  if (WIDTHS[name]) return WIDTHS[name]

  for (const [prefix, width] of Object.entries(WIDTHS)) {
    if (prefix.endsWith('-') && name.startsWith(prefix)) return width
  }

  return 1200
}

const keepPng = process.argv.includes('--keep-png')
const dir = resolve(process.cwd(), DIR)
const files = readdirSync(dir).filter((file) => file.endsWith('.png'))

let before = 0
let after = 0

for (const file of files) {
  const source = join(dir, file)
  const { name } = parse(file)
  const target = join(dir, `${name}.webp`)

  const sourceSize = statSync(source).size
  before += sourceSize

  await sharp(source)
    .resize({ width: widthFor(name), withoutEnlargement: true })
    .webp({ quality: 82, effort: 6 })
    .toFile(target)

  const targetSize = statSync(target).size
  after += targetSize

  if (!keepPng) unlinkSync(source)

  console.log(
    `${name.padEnd(20)} ${(sourceSize / 1024).toFixed(0).padStart(5)} KB -> `
    + `${(targetSize / 1024).toFixed(0).padStart(4)} KB webp @${widthFor(name)}px`,
  )
}

console.log(
  `\ntotal ${(before / 1024 / 1024).toFixed(2)} MB -> ${(after / 1024 / 1024).toFixed(2)} MB `
  + `(${Math.round((1 - after / before) * 100)}% smaller)`,
)
