import { mkdirSync, existsSync, writeFileSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import { readEnvValue } from './lib/env.mjs'

/**
 * Generates catalogue photography for the demo products.
 *
 * Separate from scripts/generate-imagery.mjs because the destination is different in
 * kind: those images are storefront chrome and belong to the Nuxt app, whereas these
 * stand in for what a real seller uploads. They therefore live next to the seeder that
 * pushes them onto the public bucket, and reach the browser through the same
 * ProductMedia rows and the same disk as any other product image — not from a static
 * path only demo data would know about.
 *
 *   node scripts/generate-catalog-imagery.mjs            # only what is missing
 *   node scripts/generate-catalog-imagery.mjs --force    # regenerate everything
 *   node scripts/generate-catalog-imagery.mjs kanepe-atlas
 *
 * Run once; the output is committed, so no API key is needed to seed or run the app.
 */

const MODEL = 'gemini-3-pro-image'
const OUT_DIR = 'apps/api/database/seeders/assets/products'

/** The same photographic set as the storefront chrome, so nothing looks borrowed. */
const STYLE = [
  'Catalogue product photography on a seamless soft cream background,',
  'even diffused studio lighting, subtle contact shadow, matte finishes,',
  'warm neutral palette of beige, taupe, stone, cream and soft off-white,',
  'photorealistic, high end residential furniture.',
  'No people, no text, no logos, no watermarks, no visible brand names.',
].join(' ')

const IMAGES = [
  {
    name: 'kanepe-boucle',
    prompt: `A modular three seat sofa in warm oatmeal bouclé with soft rounded arms and
      slim oak legs, viewed three quarters from the front. ${STYLE}`,
  },
  {
    name: 'koltuk-keten',
    prompt: `A single armchair upholstered in stone coloured linen with a low curved back
      and tapered walnut legs, viewed three quarters from the front. ${STYLE}`,
  },
  {
    name: 'sehpa-mese',
    prompt: `A round solid oak coffee table with a softly tapered pedestal base. ${STYLE}`,
  },
  {
    name: 'yemek-masasi',
    prompt: `A rectangular solid oak dining table for six with clean square legs and a
      lightly oiled finish. ${STYLE}`,
  },
  {
    name: 'sandalye-boucle',
    prompt: `A dining chair with a softly curved bouclé shell in cream and slim oak legs. ${STYLE}`,
  },
  {
    name: 'hali-yun',
    prompt: `A hand woven wool rug in sand and taupe tones with a subtle geometric weave,
      laid flat and viewed slightly from above. ${STYLE}`,
  },
  {
    name: 'lambader-linen',
    prompt: `A slim floor lamp with a brushed brass stem and a tall linen drum shade in
      warm off-white. ${STYLE}`,
  },
  {
    name: 'tavan-aydinlatma',
    prompt: `A sculptural pendant light with a pleated linen shade in warm off-white and a
      brushed brass fitting, photographed hanging against a plain background. ${STYLE}`,
  },
  {
    name: 'kitaplik-mese',
    prompt: `An open shelving unit in solid oak with five slim horizontal shelves and a
      light oiled finish, empty. ${STYLE}`,
  },
  {
    name: 'komodin-mese',
    prompt: `A two drawer bedside table in solid oak with recessed finger pulls and slim
      tapered legs. ${STYLE}`,
  },
  {
    name: 'yatak-keten',
    prompt: `An upholstered double bed frame in stone coloured linen with a low padded
      headboard, dressed in crisp white bedding. ${STYLE}`,
  },
  {
    name: 'ayna-pirinc',
    prompt: `A tall round wall mirror with a slim brushed brass frame, leaning against a
      plain background. ${STYLE}`,
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

  const response = await fetch(
    `https://generativelanguage.googleapis.com/v1beta/models/${MODEL}:generateContent?key=${key}`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        contents: [{ parts: [{ text: entry.prompt.replace(/\s+/g, ' ').trim() }] }],
        generationConfig: {
          responseModalities: ['IMAGE'],
          imageConfig: { aspectRatio: '1:1' },
        },
      }),
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

  console.log(`created  ${entry.name}.png  (${Math.round(statSync(target).size / 1024)} KB)`)
}

const queue = IMAGES.filter((entry) => only.length === 0 || only.includes(entry.name))

for (const entry of queue) {
  try {
    await generate(entry)
  } catch (error) {
    console.error(`FAILED   ${error.message}`)
  }
}

console.log('\nDone. Catalogue imagery lives in', OUT_DIR)
