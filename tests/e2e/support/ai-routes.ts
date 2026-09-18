import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { dirname } from 'node:path'
import { request as playwrightRequest } from '@playwright/test'
import { createVerifiedAccount, grantPlatformRole } from './accounts'

/**
 * Keeps the suite off the paid providers.
 *
 * Uploading a photograph now queues a reading of the room, and a customer's click queues
 * a plate: both are real provider calls that cost money. A test run creates dozens of rooms
 * and photographs, and every one of them would have been billed to the owner's account —
 * silently, twenty seconds after the test that caused it had already passed.
 *
 * So the run starts by pointing those tasks at the local simulator and ends by putting the
 * routing back exactly as it was. What was there before is written to a file rather than
 * held in memory, because setup and teardown are separate processes.
 */

const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

/**
 * The tasks a test can trigger without meaning to.
 *
 * Not only the two the upload queues. A journey that asks for a design runs the planner,
 * the reranker, the renderer and the render check, and each of those was billed for real
 * until the whole list was named here — a few kuruş to a few lira per run, unnoticed because
 * every test passed.
 */
export const BACKGROUND_TASKS = ['room_analysis', 'room_clear', 'design_plan', 'product_match_rerank', 'render_check', 'image_render_draft', 'image_render_premium', 'text_embedding'] as const

/** Which simulator model answers each task; anything not named here is text. */
const SIMULATOR_MODEL: Partial<Record<(typeof BACKGROUND_TASKS)[number], string>> = {
  // Embeddings were missing from this list for months, so every run made real vector calls
  // to Google: a few thousandths of a dollar each, dozens a run, and invisible because the
  // rate table has no line for them and the console showed the cost as zero.
  text_embedding: 'fake-embedding-1',
  room_analysis: 'fake-vision-1',
  render_check: 'fake-vision-1',
  room_clear: 'fake-image-1',
  image_render_draft: 'fake-image-1',
  image_render_premium: 'fake-image-1',
}

const SAVED = 'test-results/.ai-routes-before.json'

interface SavedRoute {
  task: string
  primary: string
  fallback: string | null
}

interface Saved {
  token: string
  routes: SavedRoute[]
}

export async function pointBackgroundTasksAtSimulator(): Promise<void> {
  const admin = await createVerifiedAccount('e2e-routes')
  await grantPlatformRole(admin.email, 'super-admin')

  const request = await playwrightRequest.newContext()
  const headers = { Authorization: `Bearer ${admin.token}`, Accept: 'application/json' }

  const overview = await request.get(`${API}/api/v1/admin/ai/overview`, { headers })

  if (!overview.ok()) {
    throw new Error(`AI overview could not be read (${overview.status()})`)
  }

  const body = await overview.json()
  const fake = body.data.providers.find((provider: { code: string }) => provider.code === 'fake')

  if (!fake) {
    throw new Error('the local simulator provider must be seeded')
  }

  const routes: SavedRoute[] = []

  for (const task of BACKGROUND_TASKS) {
    const row = body.data.tasks.find((entry: { task: string }) => entry.task === task)

    if (!row?.route) {
      continue
    }

    routes.push({ task, primary: row.route.primary_model.id, fallback: row.route.fallback_model?.id ?? null })

    const wantedCode = SIMULATOR_MODEL[task] ?? 'fake-text-1'
    const model = fake.models.find((entry: { code: string }) => entry.code === wantedCode)

    if (!model) {
      throw new Error(`simulator model ${wantedCode} for ${task} is not seeded`)
    }

    // A fallback equal to the new primary is refused by the database; drop it for the run.
    const fallback = row.route.fallback_model?.id === model.id ? null : (row.route.fallback_model?.id ?? null)

    const saved = await request.put(`${API}/api/v1/admin/ai/routes`, {
      headers,
      data: { task, primary_model_id: model.id, fallback_model_id: fallback, credit_cost: row.route.credit_cost },
    })

    if (!saved.ok()) {
      throw new Error(`${task} could not be pointed at the simulator (${saved.status()})`)
    }
  }

  await request.dispose()

  await mkdir(dirname(SAVED), { recursive: true })
  await writeFile(SAVED, JSON.stringify({ token: admin.token, routes } satisfies Saved))

  process.stdout.write(`[setup] ${routes.map(route => route.task).join(', ')} → simülatör\n`)
}

export async function restoreBackgroundTasks(): Promise<void> {
  let saved: Saved

  try {
    saved = JSON.parse(await readFile(SAVED, 'utf8')) as Saved
  } catch {
    return
  }

  const request = await playwrightRequest.newContext()
  const headers = { Authorization: `Bearer ${saved.token}`, Accept: 'application/json' }

  for (const route of saved.routes) {
    await request.put(`${API}/api/v1/admin/ai/routes`, {
      headers,
      data: { task: route.task, primary_model_id: route.primary, fallback_model_id: route.fallback },
    })
  }

  await request.dispose()
  await writeFile(SAVED, '')

  process.stdout.write(`[teardown] ${saved.routes.map(route => route.task).join(', ')} eski yönlendirmesine döndü\n`)
}
