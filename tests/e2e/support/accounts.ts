import { clearInbox, extractLink, waitForMessage } from './mailpit'

/**
 * Creates a verified account straight through the API.
 *
 * The seller journey is about onboarding, not registration — driving the storefront
 * sign-up form first would make every seller test depend on the storefront being up
 * and would bury the behaviour under setup. The verification link is still read out
 * of the delivered mail, so the account is genuinely verified rather than forced.
 */

const API_BASE = process.env.E2E_API_URL ?? 'http://localhost:58000'

export const DEFAULT_PASSWORD = 'E2eGucluParola2026'

export interface VerifiedAccount {
  email: string
  password: string
  token: string
}

export function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.floor(Math.random() * 1e4)}@refconcept.local`
}

/**
 * Posts to the API, waiting out the rate limiter rather than working around it.
 *
 * Registration is throttled to a handful of attempts per minute, which is the correct
 * production behaviour and exactly what a suite that creates a dozen accounts in a row
 * runs into. Raising the limit for tests would mean the suite no longer exercises the
 * configuration that ships; honouring `Retry-After` costs a minute at worst and keeps
 * the throttle real.
 */
async function post<T>(path: string, body: unknown, token?: string): Promise<T> {
  for (let attempt = 0; ; attempt++) {
    const response = await fetch(`${API_BASE}${path}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify(body),
    })

    if (response.ok) {
      return (await response.json()) as T
    }

    if (response.status === 429 && attempt < 3) {
      const retryAfter = Number(response.headers.get('Retry-After') ?? '60')

      await new Promise(resolve => setTimeout(resolve, (retryAfter + 1) * 1000))

      continue
    }

    throw new Error(`${path} failed: ${response.status} ${await response.text()}`)
  }
}

export async function createVerifiedAccount(prefix = 'seller'): Promise<VerifiedAccount> {
  const email = uniqueEmail(prefix)
  await clearInbox()

  await post('/api/v1/auth/register', {
    email,
    password: DEFAULT_PASSWORD,
    password_confirmation: DEFAULT_PASSWORD,
    first_name: 'Test',
    last_name: 'Satıcı',
    consents: [
      { type: 'privacy_notice', version: '2026-01' },
      { type: 'terms', version: '2026-01' },
    ],
  })

  const mail = await waitForMessage(email, { subjectContains: 'doğrula' })
  const link = extractLink(mail, '/auth/verify-email')
  const verificationToken = new URL(link).searchParams.get('token')

  if (!verificationToken) {
    throw new Error('Verification link carried no token.')
  }

  await post('/api/v1/auth/email/verify', { token: verificationToken })

  const login = await post<{ data: { token: string } }>('/api/v1/auth/login', {
    email,
    password: DEFAULT_PASSWORD,
    device_name: 'e2e',
  })

  return { email, password: DEFAULT_PASSWORD, token: login.data.token }
}

/**
 * Grants a platform role by calling artisan inside the api container.
 *
 * Reviewing applications needs an operator, and there is deliberately no API that
 * hands out platform roles — that would be a privilege-escalation endpoint.
 */
export async function grantOperatorRole(email: string): Promise<void> {
  const { execFile } = await import('node:child_process')
  const { promisify } = await import('node:util')
  const run = promisify(execFile)

  await run('docker', [
    'compose', 'exec', '-T', 'api',
    'php', 'artisan', 'refconcept:grant-role', email, 'operator',
  ])
}
