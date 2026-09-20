import { expect, test } from '@playwright/test'
import type { APIRequestContext, Page } from '@playwright/test'
import { completeBrief } from './support/brief'
import { createVerifiedAccount, grantPlatformRole } from './support/accounts'
import { gotoInteractive, waitForHydration } from './support/hydration'
import { pngBuffer } from './support/sellers'
import { fillStable } from './support/forms'
import { signInThrough } from './support/signin'

/**
 * Is the room the design?
 *
 * The product owner asked the question three times and each time the answer was an
 * impression of a screenshot. This makes it arithmetic: the design's plan says what the room
 * should contain, the layout says what is standing in it, and the two are printed side by
 * side with the difference named.
 *
 * It also takes the one picture that actually compares them. The plan screen now shows the
 * design beside the room it is being rebuilt in, so a single frame holds both — the thing
 * the owner was doing by hand with two windows.
 *
 * What it asserts is deliberately narrow. Every piece the plan marked required has to be
 * standing in the room: a design that asks for a sofa and hands back a room without one is
 * broken, whatever it looks like. Optional pieces — lamps, curtains, plants — are reported
 * and not asserted, because whether the catalogue can supply one is not this code's fault
 * and failing here would make the suite a catalogue alarm.
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

const SHOT = 'test-results/design-vs-room'

test.describe.configure({ timeout: 600_000 })
test.use({ viewport: { width: 1440, height: 900 } })

interface Placement {
  category?: string | null
  quantity?: number | null
  is_required?: boolean | null
  name?: string | null
}

/** A project with one room, the way the room screens make them. */
async function makeRoom(request: APIRequestContext, token: string): Promise<{ projectId: string, roomId: string }> {
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

  const project = await request.post(`${API}/api/v1/projects`, {
    headers,
    data: { name: `Tasarım Karşılaştırma ${Date.now()}` },
  })

  expect(project.ok(), await project.text()).toBeTruthy()

  const projectId = (await project.json()).data.id

  const room = await request.post(`${API}/api/v1/projects/${projectId}/rooms`, {
    headers,
    data: { name: 'Salon', room_type: 'living_room' },
  })

  expect(room.ok(), await room.text()).toBeTruthy()

  return { projectId, roomId: (await room.json()).data.id }
}

/** Credits, through the console an operator would use: there is no customer route. */
async function grantCredits(request: APIRequestContext, customerToken: string): Promise<void> {
  const operator = await createVerifiedAccount('karsilastirma-operator')
  await grantPlatformRole(operator.email, 'super-admin')

  const me = await request.get(`${API}/api/v1/auth/me`, {
    headers: { Authorization: `Bearer ${customerToken}`, Accept: 'application/json' },
  })

  const customerId = (await me.json()).data.id

  const granted = await request.post(`${API}/api/v1/admin/credits/wallets/${customerId}/adjust`, {
    headers: { Authorization: `Bearer ${operator.token}`, Accept: 'application/json' },
    data: { delta: 80, reason: 'E2E tasarım/oda karşılaştırması için bakiye.' },
  })

  expect(granted.ok(), await granted.text()).toBeTruthy()
}

/** How many of each category a list asks for. */
function countBy(items: Array<{ category?: string | null, quantity?: number | null }>): Map<string, number> {
  const counts = new Map<string, number>()

  for (const item of items) {
    const category = item.category ?? '?'
    const many = typeof item.quantity === 'number' && item.quantity > 0 ? item.quantity : 1

    counts.set(category, (counts.get(category) ?? 0) + many)
  }

  return counts
}

/**
 * Presses the guide from the photograph to the questions.
 *
 * Every answer here is the ordinary one: keep going, the measurements are right, leave the
 * furniture where it is. Nothing clever is chosen, because the path most people take is the
 * one most worth proving still works — and because a walk that starts removing furniture
 * spends a minute of somebody's queue on a step this test is not about.
 *
 * Bounded rather than looped on a condition: a studio that cannot be walked through should
 * fail here with the sentence the guide is stuck on, not spin until the test times out.
 */
async function guideThrough(page: Page): Promise<void> {
  const brief = page.getByRole('heading', { name: 'Hangi tarzı seviyorsunuz?' })

  // In the order they are offered; "Evet, kaldır" is deliberately not among them.
  const forward = ['Devam et', 'Evet, doğru', 'Hayır, hepsi kalsın', 'Beklemeden devam et', 'Eşyalarla devam et']

  for (let step = 0; step < 12; step++) {
    if (await brief.count() > 0) {
      return
    }

    let pressed = false

    for (const label of forward) {
      const button = page.getByRole('button', { name: label, exact: true }).first()

      if (await button.count() === 0) {
        continue
      }

      await button.click({ timeout: 10_000 }).catch(() => {})
      await page.waitForTimeout(1_200)
      pressed = true
      break
    }

    if (! pressed) {
      // Nothing to press: either the reading is still running, or the size has to be typed.
      const width = page.locator('#width')

      if (await width.count() > 0) {
        await fillStable(page, '#width', '400')
        await fillStable(page, '#length', '550')
        await fillStable(page, '#height', '260')
        await page.locator('#quality').selectOption('manual').catch(() => {})
        await page.getByRole('button', { name: 'Ölçüleri kaydet' }).click().catch(() => {})
      }

      await page.waitForTimeout(4_000)
    }
  }

  const stuck = await page.locator('[class*="StudioGuide"], main').first().innerText().catch(() => '')

  throw new Error(`Stüdyo isteklere kadar yürümedi. Ekranda:\n${stuck.slice(0, 400)}`)
}

/**
 * The newest finished design version of a room, with the plan behind it.
 *
 * Polled rather than watched on screen: the design page says "Hazır" when the picture lands,
 * and what this test needs is the list the picture was made from. Asking the API is the same
 * answer without a heading to keep in step with.
 *
 * Three calls because the payload is shaped for the screens rather than for this: the room's
 * designs, then the design for the version it is currently showing, then that version for the
 * plan. Cheap, and it reads the same rows the customer's screen does.
 */
async function waitForDesign(
  request: APIRequestContext,
  headers: Record<string, string>,
  projectId: string,
  roomId: string,
): Promise<{ id: string, plan?: { placements?: Placement[] } }> {
  const base = `${API}/api/v1/projects/${projectId}/rooms/${roomId}/designs`
  const started = Date.now()

  while (Date.now() - started < 420_000) {
    const list = await request.get(base, { headers })

    if (list.ok()) {
      for (const design of (await list.json()).data ?? []) {
        const one = await request.get(`${base}/${design.id}`, { headers })

        if (! one.ok()) {
          continue
        }

        const current = (await one.json()).data?.current_version

        if (current?.status !== 'ready') {
          continue
        }

        const version = await request.get(`${base}/${design.id}/versions/${current.id}`, { headers })

        if (! version.ok()) {
          continue
        }

        const detail = (await version.json()).data

        if ((detail?.plan?.placements?.length ?? 0) > 0) {
          return detail
        }
      }
    }

    await new Promise(resolve => setTimeout(resolve, 5_000))
  }

  throw new Error('Tasarım yedi dakikada hazır olmadı.')
}

test.describe('design vs room', () => {
  test('the room holds what the design asked for, and says what it does not', async ({ page, request }) => {
    const account = await createVerifiedAccount('karsilastirma')
    const { projectId, roomId } = await makeRoom(request, account.token)

    // A design costs credits and a new account has none; the screen says so and stops, which
    // is correct behaviour and not what this test is about.
    await grantCredits(request, account.token)

    const headers = { Authorization: `Bearer ${account.token}`, Accept: 'application/json' }

    await signInThrough(page, STOREFRONT, account.email, /\/account$/)

    // --- a room with a photograph, a size and a design -------------------------------
    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}/rooms/${roomId}`)
    await waitForHydration(page)

    const chooser = page.waitForEvent('filechooser')

    await page.getByRole('button', { name: 'Fotoğraf ekle' }).click()
    await (await chooser).setFiles({ name: 'salon.png', mimeType: 'image/png', buffer: pngBuffer(1280, 960) })

    const strip = page.getByRole('navigation', { name: 'Oda stüdyosu' })

    await expect(strip.getByText('Eşyalar', { exact: true })).toBeVisible({ timeout: 120_000 })

    /*
     * Forward the way a customer goes: by pressing the guide, not by typing a hash.
     *
     * The studio does not let anybody jump ahead — a phase opens once the one before it is
     * behind you — so a walk that navigates straight to #istekler stays on the photograph
     * and fails twenty seconds later looking for a heading that was never shown. The guide
     * asks one question at a time and the answer to all of them here is the plain one.
     */
    await guideThrough(page)

    await completeBrief(page)

    /*
     * Waited for at the door rather than on the screen.
     *
     * The design screen says "Hazır" when the picture lands, and what this test needs is the
     * plan behind it — so it asks the API, which is the same answer without a heading to
     * keep in step with.
     */
    const version = await waitForDesign(request, headers, projectId, roomId)

    const placements: Placement[] = version.plan?.placements ?? []

    expect(placements.length, 'tasarımın planı boş geldi').toBeGreaterThan(0)

    // --- the room, arranged from it ---------------------------------------------------
    await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}/rooms/${roomId}/plan?compose=${version.id}`)
    /*
     * The measurements are the threshold of the 3D door, so this is where they are asked.
     *
     * A design is drawn on a photograph and needs no metres; they start mattering when a
     * real sofa goes into a real room. A customer who likes their design and never opens the
     * plan never sees this question, which is why it is here and not in the walk above.
     */
    const saveSize = page.getByRole('button', { name: 'Kaydet ve devam et' })

    if (await saveSize.count() > 0) {
      await fillStable(page, 'input[type="number"] >> nth=0', '400')
      await fillStable(page, 'input[type="number"] >> nth=1', '550')
      await fillStable(page, 'input[type="number"] >> nth=2', '260')
      await saveSize.click()
    }

    await expect(page.locator('canvas')).toBeVisible({ timeout: 120_000 })

    // Arranging happens on load when the room is empty; this waits for it to land.
    await expect(page.getByText(/Odadaki ürünler/)).toBeVisible({ timeout: 120_000 })
    await page.waitForTimeout(6_000)

    /*
     * The picture that compares them, which is one picture now.
     *
     * The design stands at the top of the column beside the room, so a single frame holds
     * the thing asked for and the thing built — which is what the owner was doing by hand
     * with a screenshot in each window.
     */
    await page.screenshot({ path: `${SHOT}/tasarim-ve-oda.png`, animations: 'disabled', timeout: 60_000 })

    const layout = await request.get(`${API}/api/v1/projects/${projectId}/rooms/${roomId}/layout`, { headers })

    expect(layout.ok(), await layout.text()).toBeTruthy()

    const items: Array<{ category?: string | null }> = (await layout.json()).data.layout?.items ?? []

    // --- the comparison, as a table ---------------------------------------------------
    const wanted = countBy(placements)
    const standing = countBy(items)

    const required = new Set(
      placements
        .filter(placement => placement.is_required !== false)
        .map(placement => placement.category ?? '?'),
    )

    const rows: string[] = []
    const missing: string[] = []

    for (const category of new Set([...wanted.keys(), ...standing.keys()])) {
      const asked = wanted.get(category) ?? 0
      const got = standing.get(category) ?? 0

      rows.push(
        `${category.padEnd(20)} tasarım ${String(asked).padEnd(3)} oda ${String(got).padEnd(3)}`
        + `${got === asked ? '' : got < asked ? `  eksik ${asked - got}${required.has(category) ? ' (ZORUNLU)' : ''}` : `  fazla ${got - asked}`}`,
      )

      if (got < asked && required.has(category)) {
        missing.push(`${category} (${asked - got} eksik)`)
      }
    }

    console.log(`\nTasarım ${placements.length} yerleşim istedi; odada ${items.length} parça duruyor.\n`)
    console.log(rows.sort().join('\n'))
    console.log('')

    // A design that asks for a sofa and hands back a room without one is broken, whatever it
    // looks like. The optional pieces are printed above and deliberately not asserted.
    expect(missing, `tasarımın zorunlu tuttuğu parçalar odada yok: ${missing.join(', ')}`).toEqual([])
  })
})
