import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { restoreBackgroundTasks } from './ai-routes'

const run = promisify(execFile)

/**
 * Takes the suite's fixtures back out of the catalogue when it finishes.
 *
 * The fixtures are real listings, created through the real endpoints, in the real `kanepe`
 * category — that is deliberate and worth keeping, because a product inserted behind the
 * API's back proves nothing about what the basket and the checkout will accept. What was
 * missing is the other half: nothing ever removed them. A hundred and twenty-six test sofas
 * accumulated in a development catalogue of eighteen real products, each carrying the flat
 * placeholder image the fixtures upload, and the design matcher offered them to customers.
 * The rendered room looked nonsensical because the shopping list under it was.
 *
 * Runs the archiving through artisan rather than reaching into the database from here: the
 * rules about what is a fixture and what must never be touched belong in one place, next to
 * the schema they depend on, and that command refuses to run in production.
 *
 * The order matters and was wrong once. A photograph uploaded by a test queues a reading of
 * the room twenty seconds later; the routing was put back the moment the last test ended,
 * and the reading then ran against the real provider and was billed — eight times in one
 * afternoon, unnoticed, because every test had passed. So now: the fixtures' projects are
 * deleted first, which makes every pending reading stand down; the queue is watched until
 * none is left; and only then is the routing restored. If the wait times out the routing is
 * restored anyway and the run says so, because a simulator left in place is the worse fault.
 *
 * A failure here is reported and swallowed. Teardown that fails the run would turn a green
 * suite red over housekeeping, and the next run's purge picks up whatever this one left.
 */
export default async function globalTeardown(): Promise<void> {
  await artisan(['refconcept:purge-e2e-fixtures'], 'test artıkları temizlenemedi')
  await artisan(['refconcept:await-ai-queue', '--timeout=90'], 'bekleyen okumalar sönmedi')

  try {
    await restoreBackgroundTasks()
  } catch (error) {
    process.stdout.write(
      `\n[teardown] AI yönlendirmesi geri alınamadı: ${error instanceof Error ? error.message : String(error)}\n`,
    )
  }
}

async function artisan(args: string[], failure: string): Promise<void> {
  try {
    const { stdout } = await run(
      'docker',
      ['compose', 'exec', '-T', 'api', 'php', 'artisan', ...args],
      { cwd: process.cwd(), timeout: 150_000 },
    )

    process.stdout.write(`\n[teardown] ${stdout.trim()}\n`)
  } catch (error) {
    process.stdout.write(`\n[teardown] ${failure}: ${error instanceof Error ? error.message : String(error)}\n`)
  }
}
