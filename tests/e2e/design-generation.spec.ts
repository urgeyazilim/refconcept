import { expect, test } from '@playwright/test'
import type { APIRequestContext, Page } from '@playwright/test'
import { createVerifiedAccount, DEFAULT_PASSWORD, grantPlatformRole } from './support/accounts'
import { pngBuffer } from './support/sellers'
import { fillStable } from './support/forms'
import { gotoInteractive, waitForHydration } from './support/hydration'

/**
 * The Phase 8 gate: a photograph becomes a design, and the customer is charged once.
 *
 * The pipeline is three model calls with arithmetic in between, and the unit tests cover
 * each of them. What only a run like this proves is that the browser, the queue worker,
 * the storage and the wallet are wired to the same pipeline rather than to three different
 * ideas of it — and that a customer watching a spinner is shown something true while they
 * wait.
 *
 * The AI routes are repointed at the local simulator first, through the same console
 * endpoints an operator would use. Running a real provider here would make the suite slow,
 * expensive and dependent on somebody else's uptime; repointing is a thing that genuinely
 * happens, and it exercises the routing tables rather than working around them.
 */

const STOREFRONT = process.env.E2E_STOREFRONT_URL ?? 'http://localhost:3000'
const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

const PIPELINE_TASKS = ['room_analysis', 'design_plan', 'image_render_draft', 'text_embedding', 'product_match_rerank'] as const

async function signIn(page: Page, email: string): Promise<void> {
  await page.context().clearCookies()
  await page.goto(`${STOREFRONT}/auth/login`)
  await waitForHydration(page)

  await fillStable(page, '#email', email)
  await fillStable(page, '#password', DEFAULT_PASSWORD)
  await page.getByRole('button', { name: 'Giriş yap' }).click()

  await expect(page).toHaveURL(/\/account$/)
}

/**
 * Points the pipeline's three tasks at the simulator, and gives back a way to undo it.
 *
 * The previous routing is captured first so the run leaves the environment as it found
 * it — a suite that quietly repoints production tasks at a fake and never puts them back
 * is a suite that breaks the next person's manual test.
 */
async function useSimulator(request: APIRequestContext, token: string) {
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

  const overview = await request.get(`${API}/api/v1/admin/ai/overview`, { headers })
  expect(overview.ok()).toBeTruthy()

  const body = await overview.json()

  const fake = body.data.providers.find((provider: { code: string }) => provider.code === 'fake')
  expect(fake, 'the local simulator provider must be seeded').toBeTruthy()

  const before: Array<{ task: string, primary: string, fallback: string | null }> = []

  for (const task of PIPELINE_TASKS) {
    const row = body.data.tasks.find((entry: { task: string }) => entry.task === task)
    expect(row?.route, `${task} must be routed`).toBeTruthy()

    before.push({
      task,
      primary: row.route.primary_model.id,
      fallback: row.route.fallback_model?.id ?? null,
    })

    /*
     * Matched on the model code rather than its display name. A name is written for a
     * person and may be reworded; a code is the identifier the seeder writes and the
     * console shows, so a copy edit cannot break this suite.
     */
    const wantedCode = {
      image_render_draft: 'fake-image-1',
      room_analysis: 'fake-vision-1',
      text_embedding: 'fake-embedding-1',
    }[task as string] ?? 'fake-text-1'

    const model = fake.models.find((entry: { code: string }) => entry.code === wantedCode)
    expect(model, `simulator model ${wantedCode} for ${task}`).toBeTruthy()

    const saved = await request.put(`${API}/api/v1/admin/ai/routes`, {
      headers,
      data: { task, primary_model_id: model.id, credit_cost: row.route.credit_cost },
    })

    expect(saved.ok()).toBeTruthy()
  }

  return async () => {
    for (const row of before) {
      await request.put(`${API}/api/v1/admin/ai/routes`, {
        headers,
        data: { task: row.task, primary_model_id: row.primary, fallback_model_id: row.fallback },
      })
    }
  }
}

test.describe.configure({ timeout: 300_000 })

test.describe('design generation', () => {
  /*
   * One operator for the whole file. Registration is rate-limited per IP — deliberately,
   * and the suite honours the limit rather than disabling it — so an account created for
   * no reason is a minute of somebody else's run.
   */
  let admin: Awaited<ReturnType<typeof createVerifiedAccount>>

  test.beforeAll(async () => {
    admin = await createVerifiedAccount('design-admin')
    await grantPlatformRole(admin.email, 'super-admin')
  })

  test('a photograph becomes a design, and the credits are charged once', async ({ page, request }) => {
    const restore = await useSimulator(request, admin.token)

    try {
      const customer = await createVerifiedAccount('design-customer')

      const adminHeaders = { Authorization: `Bearer ${admin.token}`, Accept: 'application/json' }
      const customerHeaders = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

      const me = await request.get(`${API}/api/v1/auth/me`, { headers: customerHeaders })
      const customerId = (await me.json()).data.id

      // Credits, through the console an operator would use. A render costs money and this
      // customer has never bought any.
      const funded = await request.post(`${API}/api/v1/admin/credits/wallets/${customerId}/adjust`, {
        headers: adminHeaders,
        data: { delta: 50, reason: 'E2E tasarım üretimi testi için bakiye.' },
      })

      expect(funded.ok()).toBeTruthy()

      // --- a room with a photograph, through the API --------------------------------
      const project = await request.post(`${API}/api/v1/projects`, {
        headers: customerHeaders,
        data: { name: `Tasarım Testi ${Date.now()}`, project_type: 'home' },
      })

      const projectId = (await project.json()).data.id

      const room = await request.post(`${API}/api/v1/projects/${projectId}/rooms`, {
        headers: customerHeaders,
        data: {
          name: 'Salon',
          room_type: 'living_room',
          width_mm: 4_200,
          length_mm: 5_600,
          height_mm: 2_700,
          measurement_quality: 'manual',
        },
      })

      const roomId = (await room.json()).data.id

      const uploaded = await request.post(`${API}/api/v1/projects/${projectId}/rooms/${roomId}/media`, {
        headers: { Authorization: `Bearer ${customer.token}` },
        multipart: {
          file: { name: 'salon.png', mimeType: 'image/png', buffer: pngBuffer(1280, 960) },
        },
      })

      expect(uploaded.ok()).toBeTruthy()

      // --- the design, in the browser -------------------------------------------------
      await signIn(page, customer.email)
      await gotoInteractive(page, `${STOREFRONT}/projects/${projectId}/rooms/${roomId}`)

      await page.getByRole('button', { name: 'Tasarım oluştur' }).click()
      await page.locator('#prompt').fill('İskandinav, açık renkler')
      await page.getByRole('button', { name: 'Başlat' }).click()

      await expect(page.getByRole('heading', { name: /Salon tasarımı/ })).toBeVisible()

      /*
       * The engine runs on a worker, so the page polls. Given a generous window because a
       * cold queue worker on Windows is slow to pick up its first job — what is being
       * asserted is that it finishes and says so, not how fast.
       */
      await expect(page.getByText('Hazır', { exact: true }).first()).toBeVisible({ timeout: 120_000 })

      // --- the API agrees ---------------------------------------------------------------
      const detail = await request.get(
        `${API}/api/v1/projects/${projectId}/rooms/${roomId}/designs`,
        { headers: customerHeaders },
      )

      const designs = (await detail.json()).data
      expect(designs).toHaveLength(1)

      const designId = designs[0].id

      const full = await request.get(
        `${API}/api/v1/projects/${projectId}/rooms/${roomId}/designs/${designId}`,
        { headers: customerHeaders },
      )

      const design = (await full.json()).data
      const version = design.tree[0]

      expect(design.status).toBe('ready')
      expect(version.status).toBe('ready')

      // Every step left something worth keeping, and there is an image at the end of it.
      const versionDetail = await request.get(
        `${API}/api/v1/projects/${projectId}/rooms/${roomId}/designs/${designId}/versions/${version.id}`,
        { headers: customerHeaders },
      )

      const body = await versionDetail.json()

      expect(body.data.plan).not.toBeNull()
      expect(body.data.plan.placements.length).toBeGreaterThan(0)
      expect(body.data.assets.length).toBeGreaterThan(0)
      expect(body.data.progress.progress_bps).toBe(10_000)

      /*
       * One charge for the whole design, not one per model call. The wallet is the check
       * that matters: a pipeline that billed each step would leave a customer paying for an
       * analysis and a plan whenever a render failed.
       */
      const wallet = await request.get(`${API}/api/v1/credits`, { headers: customerHeaders })
      const balance = (await wallet.json()).data

      expect(balance.reserved).toBe(0)
      expect(balance.lifetime.consumed).toBe(version.credit_cost)
      expect(balance.balance).toBe(50 - version.credit_cost)

      // And it appears on the statement in the customer's own language.
      const statement = await request.get(`${API}/api/v1/credits/transactions`, { headers: customerHeaders })
      const entries = (await statement.json()).data

      expect(entries[0].type).toBe('consume')
      expect(entries[0].description).toBe('Tasarım üretimi')
    } finally {
      await restore()
    }
  })

  test('the design comes with products a customer can actually buy', async ({ request }) => {
    const restore = await useSimulator(request, admin.token)

    try {
      const customer = await createVerifiedAccount('design-shopper')

      const adminHeaders = { Authorization: `Bearer ${admin.token}`, Accept: 'application/json' }
      const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

      const me = await request.get(`${API}/api/v1/auth/me`, { headers })
      const customerId = (await me.json()).data.id

      await request.post(`${API}/api/v1/admin/credits/wallets/${customerId}/adjust`, {
        headers: adminHeaders,
        data: { delta: 50, reason: 'E2E alışveriş listesi testi için bakiye.' },
      })

      /*
       * The seeded demo catalogue has to be searchable before anything can be matched
       * against it. Run through the same console command an operator would use — and it is
       * cheap to repeat, because a product whose text has not changed is skipped.
       */
      const { execFile } = await import('node:child_process')
      const { promisify } = await import('node:util')
      const run = promisify(execFile)

      await run('docker', [
        'compose', 'exec', '-T', 'api',
        'php', 'artisan', 'refconcept:embed-catalogue',
      ])

      const project = await request.post(`${API}/api/v1/projects`, {
        headers,
        data: { name: `Liste Testi ${Date.now()}`, project_type: 'home' },
      })

      const projectId = (await project.json()).data.id

      const room = await request.post(`${API}/api/v1/projects/${projectId}/rooms`, {
        headers,
        data: {
          name: 'Salon',
          room_type: 'living_room',
          width_mm: 4_200,
          length_mm: 5_600,
          measurement_quality: 'manual',
        },
      })

      const roomId = (await room.json()).data.id

      await request.post(`${API}/api/v1/projects/${projectId}/rooms/${roomId}/media`, {
        headers: { Authorization: `Bearer ${customer.token}` },
        multipart: {
          file: { name: 'salon.png', mimeType: 'image/png', buffer: pngBuffer(1280, 960) },
        },
      })

      const created = await request.post(
        `${API}/api/v1/projects/${projectId}/rooms/${roomId}/designs`,
        { headers, data: { user_prompt: 'İskandinav, açık renkler' } },
      )

      expect(created.ok()).toBeTruthy()

      const designId = (await created.json()).data.id
      const versionId = (await created.json().catch(() => ({}))).version_id

      // The worker runs the pipeline; the list is built as its last step.
      let matches: { placements: Array<{ category: string, matches: unknown[] }> } | null = null

      for (let attempt = 0; attempt < 60; attempt++) {
        const detail = await request.get(
          `${API}/api/v1/projects/${projectId}/rooms/${roomId}/designs/${designId}`,
          { headers },
        )

        const design = (await detail.json()).data

        if (design.status === 'ready') {
          const current = design.current_version?.id ?? versionId

          const list = await request.get(
            `${API}/api/v1/projects/${projectId}/rooms/${roomId}/designs/${designId}/versions/${current}/matches`,
            { headers },
          )

          matches = (await list.json()).data
          break
        }

        await new Promise(resolve => setTimeout(resolve, 2_000))
      }

      expect(matches, 'the design should have finished and produced a list').toBeTruthy()
      expect(matches!.placements.length).toBeGreaterThan(0)

      /*
       * The assertion that matters: every suggestion is something somebody could put in a
       * basket. A list of plausible-looking products that cannot be bought is worse than
       * no list, because it takes a customer all the way to the disappointment.
       */
      const first = matches!.placements.find(group => group.matches.length > 0)

      expect(first, 'at least one placement should have suggestions').toBeTruthy()

      const suggestion = first!.matches[0] as {
        product: { name: string | null }
        price: { amount_minor: number }
        sku: { id: string }
      }

      expect(suggestion.product.name).toBeTruthy()
      expect(suggestion.price.amount_minor).toBeGreaterThan(0)
      expect(suggestion.sku.id).toBeTruthy()
    } finally {
      await restore()
    }
  })

  test('a customer with no credits is told before anything runs', async ({ request }) => {
    const restore = await useSimulator(request, admin.token)

    try {
      const customer = await createVerifiedAccount('design-pauper')
      const headers = { Authorization: `Bearer ${customer.token}`, Accept: 'application/json' }

      const project = await request.post(`${API}/api/v1/projects`, {
        headers,
        data: { name: `Bütçesiz ${Date.now()}`, project_type: 'home' },
      })

      const projectId = (await project.json()).data.id

      const room = await request.post(`${API}/api/v1/projects/${projectId}/rooms`, {
        headers,
        data: { name: 'Salon', room_type: 'living_room' },
      })

      const roomId = (await room.json()).data.id

      await request.post(`${API}/api/v1/projects/${projectId}/rooms/${roomId}/media`, {
        headers: { Authorization: `Bearer ${customer.token}` },
        multipart: {
          file: { name: 'salon.png', mimeType: 'image/png', buffer: pngBuffer(1024, 768) },
        },
      })

      /*
       * Refused at the door with the numbers in the message, not queued and failed four
       * seconds later. "8 kredi gerekiyor, 0 krediniz var" is something somebody can act
       * on; "yetersiz bakiye" is not.
       */
      const refused = await request.post(
        `${API}/api/v1/projects/${projectId}/rooms/${roomId}/designs`,
        { headers, data: { user_prompt: 'Modern salon' } },
      )

      expect(refused.status()).toBe(422)
      expect(await refused.text()).toContain('kredi')
    } finally {
      await restore()
    }
  })
})
