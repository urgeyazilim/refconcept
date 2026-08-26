/**
 * A load smoke test — not a benchmark.
 *
 * The question is not "how fast is it", which depends on a laptop and means nothing. The
 * question is "does anything collapse under concurrency": a connection pool that runs out,
 * a query that deadlocks when two customers hit it at once, a route that answers 200 for
 * one caller and 500 for twenty.
 *
 * So the thresholds are deliberately loose and the failure conditions are absolute. Any
 * 5xx fails the run. Any request that never answers fails the run. A p95 above the ceiling
 * is reported and does not fail, because a shared development machine is not a production
 * host and a timing gate here would fail for reasons that have nothing to do with the code.
 *
 *   node scripts/load-smoke.mjs
 *   API=http://localhost:58000 CONCURRENCY=40 node scripts/load-smoke.mjs
 */

const API = process.env.API ?? 'http://localhost:58000'
const CONCURRENCY = Number(process.env.CONCURRENCY ?? 25)
const ROUNDS = Number(process.env.ROUNDS ?? 4)

/** The public paths a real burst of traffic actually lands on. */
const TARGETS = [
  { name: 'health', path: '/api/health' },
  { name: 'catalogue', path: '/api/v1/catalog/products?per_page=24' },
  { name: 'search', path: '/api/v1/catalog/products?search=koltuk' },
  { name: 'categories', path: '/api/v1/catalog/categories' },
  { name: 'facets', path: '/api/v1/catalog/vocabulary' },
]

async function timedFetch(path) {
  const started = performance.now()

  try {
    const response = await fetch(API + path, { headers: { Accept: 'application/json' } })

    // The body is read on purpose: a response that streams an error after its headers is
    // a success by status alone.
    await response.text()

    return { status: response.status, ms: performance.now() - started }
  } catch (error) {
    return { status: 0, ms: performance.now() - started, error: String(error) }
  }
}

function percentile(values, p) {
  const sorted = [...values].sort((a, b) => a - b)

  return sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * p))] ?? 0
}

const results = new Map(TARGETS.map(target => [target.name, []]))
const failures = []

console.log(`load smoke → ${API}`)
console.log(`${CONCURRENCY} concurrent × ${ROUNDS} rounds × ${TARGETS.length} endpoints\n`)

for (let round = 1; round <= ROUNDS; round++) {
  for (const target of TARGETS) {
    const burst = Array.from({ length: CONCURRENCY }, () => timedFetch(target.path))
    const settled = await Promise.all(burst)

    for (const result of settled) {
      results.get(target.name).push(result.ms)

      // 5xx and "no answer at all" are the two outcomes this script exists to catch.
      if (result.status === 0 || result.status >= 500) {
        failures.push(`${target.name}: ${result.status === 0 ? result.error : 'HTTP ' + result.status}`)
      }
    }
  }
}

let slow = 0

for (const target of TARGETS) {
  const times = results.get(target.name)
  const p50 = percentile(times, 0.5)
  const p95 = percentile(times, 0.95)

  // A ceiling worth reporting rather than enforcing: past a second, something is queueing.
  const flag = p95 > 1000 ? '  ← slow' : ''

  if (p95 > 1000) slow++

  console.log(
    `  ${target.name.padEnd(12)} n=${String(times.length).padStart(4)}  `
    + `p50=${p50.toFixed(0).padStart(5)}ms  p95=${p95.toFixed(0).padStart(5)}ms${flag}`,
  )
}

console.log('')

if (failures.length > 0) {
  const summary = [...new Set(failures)].slice(0, 10)

  console.error(`✖ ${failures.length} request(s) failed under load:`)
  summary.forEach(line => console.error(`   ${line}`))
  process.exit(1)
}

if (slow > 0) {
  // Reported, not failed. See the note at the top: a shared development machine is not a
  // production host, and a timing gate here would fail for reasons unrelated to the code.
  console.log(`⚠ ${slow} endpoint(s) above the 1s p95 guideline — worth a look, not a failure.`)
}

console.log('✔ load smoke passed — no 5xx and no dropped requests under concurrency.')
