import { request as playwrightRequest } from '@playwright/test'
import { createVerifiedAccount, E2E_EMAIL_DOMAIN, grantPlatformRole } from './accounts'

/**
 * Checks that the suite will be kept off the paid providers, and changes nothing.
 *
 * Uploading a photograph queues a reading of the room, and a customer's click queues a
 * plate: both are real provider calls that cost money. A run creates dozens of rooms and
 * photographs, and every one would be billed to the owner's account — silently, twenty
 * seconds after the test that caused it had already passed.
 *
 * This file used to prevent that by pointing the platform's routing table at the simulator
 * for the length of a run and putting it back afterwards. That is a switch with no fence
 * around it, and it did exactly what a switch with no fence does. One run died before its
 * teardown, and for six hours every customer of the running installation was answered by
 * the simulator: the product owner photographed their living room, waited, was handed a
 * canned living room in zero seconds, and asked whether the system was working at all.
 * Another run restored the routing before the last queued reading had finished, and the
 * real provider was billed eight times in an afternoon with every test green.
 *
 * Nothing global moves now. The server decides per account — a job belonging to an address
 * on this suite's e-mail domain is answered by the simulator, everybody else is untouched —
 * so a run that dies halfway leaves no routes to put back and reaches nobody else's work.
 * See `AiGateway::simulatedFor()`, and `AiGateway::realOnly()`, which refuses a stored route
 * that names the simulator so this can never be arranged by hand again.
 *
 * What is left here is the check. The per-account path is silent when it is misconfigured —
 * it simply does not simulate, and the run bills real money — so the run refuses to start
 * unless the server agrees on the domain and the stand-in models are seeded.
 */

const API = process.env.E2E_API_URL ?? 'http://localhost:58000'

/**
 * The tasks a test can trigger without meaning to.
 *
 * Not only the two the upload queues. A journey that asks for a design runs the planner,
 * the reranker, the renderer and the render check, and each of those was billed for real
 * until the whole list was named — a few kuruş to a few lira per run, unnoticed because
 * every test passed. The list is no longer used to rewrite anything; it is what the check
 * below needs a stand-in for.
 */
export const BACKGROUND_TASKS = ['room_analysis', 'room_clear', 'design_plan', 'product_match_rerank', 'render_check', 'image_render_draft', 'image_render_premium', 'text_embedding'] as const

/** One stand-in per modality, which is all the per-account swap looks for. */
const REQUIRED_SIMULATOR_MODELS = ['fake-text-1', 'fake-vision-1', 'fake-image-1', 'fake-embedding-1'] as const

export async function assertSimulatorAnswersThisSuite(): Promise<void> {
  const admin = await createVerifiedAccount('e2e-routes')
  await grantPlatformRole(admin.email, 'super-admin')

  const request = await playwrightRequest.newContext()
  const headers = { Authorization: `Bearer ${admin.token}`, Accept: 'application/json' }

  try {
    const overview = await request.get(`${API}/api/v1/admin/ai/overview`, { headers })

    if (!overview.ok()) {
      throw new Error(`AI overview could not be read (${overview.status()})`)
    }

    const body = await overview.json()

    /*
     * The domain the server simulates for has to be the one this suite signs up with.
     * A mismatch is the expensive failure and the quiet one: everything passes and every
     * call is real, which is how the embeddings went to Google for months.
     */
    const domain = String(body.data.simulated_email_domain ?? '')

    if (domain.toLowerCase() !== E2E_EMAIL_DOMAIN) {
      throw new Error(
        `the API simulates for "${domain || '(off)'}" but this suite signs up on "${E2E_EMAIL_DOMAIN}"; `
        + 'set REFCONCEPT_SIMULATED_EMAIL_DOMAIN on the API and restart it',
      )
    }

    const fake = body.data.providers.find((provider: { code: string }) => provider.code === 'fake')

    if (!fake) {
      throw new Error('the local simulator provider must be seeded')
    }

    const seeded = new Set(fake.models.map((model: { code: string }) => model.code))
    const missing = REQUIRED_SIMULATOR_MODELS.filter(code => !seeded.has(code))

    if (missing.length > 0) {
      throw new Error(`simulator models not seeded: ${missing.join(', ')}`)
    }

    // A task with no route at all fails every journey that touches it, and reads like a
    // broken page rather than a missing row. Worth saying plainly at the start of a run.
    const unrouted = BACKGROUND_TASKS.filter(
      task => !body.data.tasks.find((entry: { task: string, route: unknown }) => entry.task === task)?.route,
    )

    if (unrouted.length > 0) {
      throw new Error(`no active route for: ${unrouted.join(', ')}`)
    }
  }
  finally {
    await request.dispose()
  }

  process.stdout.write(`[setup] ${E2E_EMAIL_DOMAIN} hesapları simülatörle yanıtlanacak; yönlendirme tablosuna dokunulmadı\n`)
}
