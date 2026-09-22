/**
 * Dates as Turkey reads them, on the server and in the browser alike.
 *
 * `toLocaleDateString` with no `timeZone` asks whichever machine is running it. The server
 * runs in UTC and the customer's browser runs in Europe/Istanbul, so anything stored near
 * midnight comes out as two different days: Vue reports a hydration mismatch and repaints the
 * node, and a customer reading from abroad is shown a date their invoice does not say.
 *
 * Pinning the zone settles both at once. The same instant becomes one date everywhere, and it
 * is the date the business actually operates in.
 */
const ZONE = 'Europe/Istanbul'
const LOCALE = 'tr-TR'

type Moment = string | Date | null | undefined

function instant(value: Moment): Date | null {
  if (value === null || value === undefined || value === '') return null

  const date = value instanceof Date ? value : new Date(value)

  return Number.isNaN(date.getTime()) ? null : date
}

function format(value: Moment, options: Intl.DateTimeFormatOptions): string {
  const date = instant(value)

  return date === null ? '' : date.toLocaleString(LOCALE, { timeZone: ZONE, ...options })
}

/** `18 Eylül 2026` */
export function longDate(value: Moment): string {
  return format(value, { day: 'numeric', month: 'long', year: 'numeric' })
}

/** `Eylül 2026` */
export function monthAndYear(value: Moment): string {
  return format(value, { month: 'long', year: 'numeric' })
}

/** `18.09.2026` */
export function shortDate(value: Moment): string {
  return format(value, { day: '2-digit', month: '2-digit', year: 'numeric' })
}

/** `18.09.2026 17:47:32` */
export function dateAndTime(value: Moment): string {
  return format(value, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}
