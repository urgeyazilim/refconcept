/**
 * Captures storefront screenshots for design review.
 *
 *   node scripts/screenshot.mjs .shots
 *
 * Looking at rendered pages next to design_refs/ is the only reliable way to judge
 * the UI; reading the markup is not.
 */
import { chromium } from '@playwright/test'
import { mkdirSync } from 'node:fs'

const OUT = process.argv[2] ?? '.shots'
mkdirSync(OUT, { recursive: true })

const HIDE_DEVTOOLS = '#nuxt-devtools-anchor,.nuxt-devtools-anchor{display:none !important}'

const targets = [
  { name: 'home', path: '/', full: true },
  { name: 'register', path: '/auth/register', full: true },
]

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } })

for (const target of targets) {
  // 'networkidle' never settles with the devtools websocket open.
  await page.goto(`http://localhost:3000${target.path}`, { waitUntil: 'load', timeout: 120_000 })
  await page.addStyleTag({ content: HIDE_DEVTOOLS }).catch(() => {})

  // Lazy images only load once scrolled into view.
  await page.evaluate(async () => {
    for (let y = 0; y < document.body.scrollHeight; y += 600) {
      window.scrollTo(0, y)
      await new Promise((r) => setTimeout(r, 120))
    }
    window.scrollTo(0, 0)
  })

  await page.waitForFunction(
    () => Array.from(document.images).every((img) => img.complete),
    undefined,
    { timeout: 60_000 },
  ).catch(() => {})

  await page.waitForTimeout(1200)
  await page.screenshot({ path: `${OUT}/${target.name}.png`, fullPage: target.full })
  console.log('shot:', target.name)
}

await browser.close()
