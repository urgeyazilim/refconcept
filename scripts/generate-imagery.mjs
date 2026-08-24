import { mkdirSync, existsSync, writeFileSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import { readEnvValue } from './lib/env.mjs'

/**
 * Generates the storefront's interior photography.
 *
 * Run once; the output is committed as ordinary static assets, so the API key is not
 * needed to build or run the app afterwards. Existing files are skipped, which keeps
 * a re-run from spending on images that already exist.
 *
 *   node scripts/generate-imagery.mjs            # only what is missing
 *   node scripts/generate-imagery.mjs --force    # regenerate everything
 *   node scripts/generate-imagery.mjs hero       # one entry by name
 *
 * Art direction comes from 21_DESIGN_SYSTEM_UI_SPEC.md §3.3 and the approved
 * references: bright, soft, neutral, beige/taupe/stone/cream, clean architectural
 * lines, natural daylight, no people, no text, no visible branding.
 */

const MODEL = 'gemini-3-pro-image'
const OUT_DIR = 'apps/storefront/public/images'

/** Shared style suffix so every image reads as one photographic set. */
const STYLE = [
  'Editorial interior photography, natural daylight from a large window,',
  'warm neutral palette of beige, taupe, stone, cream and soft off-white,',
  'clean architectural lines, calm and uncluttered, matte finishes,',
  'shallow depth of field, soft shadows, photorealistic, high end residential.',
  'No people, no text, no logos, no watermarks, no visible brand names.',
].join(' ')

const IMAGES = [
  {
    name: 'hero-living-room',
    aspectRatio: '16:9',
    prompt: `A serene modern living room in a high end apartment. A low modular sofa in warm
      oatmeal bouclé, a round oak coffee table, a large woven wool rug, a slim floor lamp,
      and a tall olive tree in a stone planter. Floor to ceiling windows on the right with
      sheer linen curtains diffusing afternoon light. Wide horizontal composition with
      generous empty space on the left third for text overlay. ${STYLE}`,
  },
  {
    name: 'room-living',
    aspectRatio: '4:3',
    prompt: `A warm minimal living room corner: a cream three seat sofa with linen cushions,
      a travertine side table, and a soft wool throw. Plaster walls in warm white. ${STYLE}`,
  },
  {
    name: 'room-bedroom',
    aspectRatio: '4:3',
    prompt: `A calm bedroom with an upholstered bed in stone coloured linen, crisp white
      bedding, two slim oak nightstands and warm pendant lights. Soft morning light. ${STYLE}`,
  },
  {
    name: 'room-kitchen',
    aspectRatio: '4:3',
    prompt: `A refined kitchen with handleless matte clay coloured cabinetry, a honed
      travertine island, brushed brass tapware and open oak shelving with simple ceramics. ${STYLE}`,
  },
  {
    name: 'room-dining',
    aspectRatio: '4:3',
    prompt: `A dining area with a solid oak table, four soft curved chairs in bouclé, a
      sculptural linen pendant above, and a large window with a garden view. ${STYLE}`,
  },
  {
    name: 'before-room',
    aspectRatio: '4:3',
    prompt: `An ordinary empty apartment living room before renovation: bare beige walls,
      plain laminate flooring, a single window, no furniture, slightly flat lighting.
      Honest and unstyled, the kind of photo a homeowner takes on a phone.
      Photorealistic. No people, no text, no logos.`,
  },
  {
    name: 'product-sofa',
    aspectRatio: '1:1',
    prompt: `Product photograph of a modular three seat sofa in warm oatmeal bouclé fabric,
      centred on a seamless soft cream background, even studio lighting, subtle contact
      shadow. Catalogue style. ${STYLE}`,
  },
  {
    name: 'product-table',
    aspectRatio: '1:1',
    prompt: `Product photograph of a round solid oak coffee table with a softly tapered
      pedestal base, centred on a seamless soft cream background, even studio lighting,
      subtle contact shadow. Catalogue style. ${STYLE}`,
  },
  {
    name: 'product-rug',
    aspectRatio: '1:1',
    prompt: `Product photograph of a hand woven wool rug in sand and taupe tones with a
      subtle geometric weave, laid flat and viewed slightly from above on a seamless soft
      cream background, even studio lighting. Catalogue style. ${STYLE}`,
  },
]

const args = process.argv.slice(2)
const force = args.includes('--force')
const only = args.filter((a) => !a.startsWith('--'))

const key = readEnvValue('GOOGLE_AI_API_KEY')
mkdirSync(resolve(process.cwd(), OUT_DIR), { recursive: true })

async function generate(entry) {
  const target = resolve(process.cwd(), OUT_DIR, `${entry.name}.png`)

  if (existsSync(target) && !force) {
    console.log(`skip     ${entry.name} (exists)`)

    return
  }

  const body = {
    contents: [{ parts: [{ text: entry.prompt.replace(/\s+/g, ' ').trim() }] }],
    generationConfig: {
      responseModalities: ['IMAGE'],
      imageConfig: { aspectRatio: entry.aspectRatio },
    },
  }

  const response = await fetch(
    `https://generativelanguage.googleapis.com/v1beta/models/${MODEL}:generateContent?key=${key}`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    },
  )

  if (!response.ok) {
    const detail = (await response.text()).slice(0, 500)
    throw new Error(`${entry.name}: HTTP ${response.status} — ${detail}`)
  }

  const payload = await response.json()
  const parts = payload.candidates?.[0]?.content?.parts ?? []
  const image = parts.find((part) => part.inlineData?.data)

  if (!image) {
    const text = parts.map((p) => p.text).filter(Boolean).join(' ').slice(0, 300)
    throw new Error(`${entry.name}: no image returned. ${text}`)
  }

  writeFileSync(target, Buffer.from(image.inlineData.data, 'base64'))

  const kb = Math.round(statSync(target).size / 1024)
  console.log(`created  ${entry.name}.png  (${entry.aspectRatio}, ${kb} KB)`)
}

const queue = IMAGES.filter((entry) => only.length === 0 || only.includes(entry.name))

for (const entry of queue) {
  try {
    await generate(entry)
  } catch (error) {
    console.error(`FAILED   ${error.message}`)
  }
}

console.log('\nDone. Images live in', OUT_DIR)
