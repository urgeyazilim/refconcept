/**
 * Minimal Mailpit client.
 *
 * The E2E suite reads verification and reset links out of the actual delivered mail
 * rather than pulling tokens from the database. That is what makes these tests prove
 * the whole chain — queue worker included — instead of only the HTTP layer.
 */

const MAILPIT_URL = process.env.E2E_MAILPIT_URL ?? 'http://localhost:58025'

interface MailpitSummary {
  ID: string
  To: Array<{ Address: string }>
  Subject: string
  Created: string
}

interface MailpitMessage {
  ID: string
  Text: string
  HTML: string
}

async function json<T>(path: string): Promise<T> {
  const response = await fetch(`${MAILPIT_URL}${path}`)

  if (!response.ok) {
    throw new Error(`Mailpit ${path} failed: ${response.status}`)
  }

  return (await response.json()) as T
}

export async function clearInbox(): Promise<void> {
  await fetch(`${MAILPIT_URL}/api/v1/messages`, { method: 'DELETE' })
}

/**
 * Waits for a message addressed to `recipient`.
 *
 * Notifications are queued, so the message appears only after the worker picks the
 * job up — polling is the honest way to wait for that, and a timeout here is a real
 * failure signal (the worker is down) rather than flakiness to paper over.
 */
export async function waitForMessage(
  recipient: string,
  { timeoutMs = 45_000, subjectContains }: { timeoutMs?: number, subjectContains?: string } = {},
): Promise<MailpitMessage> {
  const deadline = Date.now() + timeoutMs

  while (Date.now() < deadline) {
    const inbox = await json<{ messages: MailpitSummary[] }>('/api/v1/messages?limit=50')

    const match = inbox.messages.find((message) => {
      const toMatches = message.To.some((to) => to.Address.toLowerCase() === recipient.toLowerCase())
      const subjectMatches = subjectContains ? message.Subject.includes(subjectContains) : true

      return toMatches && subjectMatches
    })

    if (match) {
      return json<MailpitMessage>(`/api/v1/message/${match.ID}`)
    }

    await new Promise((resolve) => setTimeout(resolve, 1000))
  }

  throw new Error(
    `No mail for ${recipient} within ${timeoutMs}ms. Is the queue worker running?`,
  )
}

/**
 * Pulls the first storefront link out of a message body.
 */
export function extractLink(message: MailpitMessage, pathFragment: string): string {
  const body = `${message.Text}\n${message.HTML}`
  const pattern = new RegExp(`https?://[^\\s"'<>]*${pathFragment}[^\\s"'<>]*`, 'i')
  const match = body.match(pattern)

  if (!match) {
    throw new Error(`No link containing "${pathFragment}" found in the message.`)
  }

  // Mail bodies are HTML-escaped; &amp; in a query string would break the token.
  return match[0].replace(/&amp;/g, '&')
}
