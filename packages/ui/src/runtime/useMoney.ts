/**
 * Conversion between what a seller types and what the wire carries.
 *
 * The API only ever accepts and returns integer minor units. That boundary is
 * deliberate — a decimal string is a float waiting to happen — but a form still has
 * to let somebody type "48.900,00". This is the single place that translation
 * happens, so a price entered on one screen cannot round differently on another.
 *
 * Turkish convention uses "." for thousands and "," for the decimal separator, which
 * is the exact reverse of the English one. Both are accepted on input, because a
 * seller pasting from an English spreadsheet should not silently list a sofa at
 * 48,90 ₺.
 */

const MINOR_SCALE = 2

/** Minor units → the string a Turkish keyboard would have produced. */
export function formatMinor(minor: number | null | undefined, currency = 'TRY'): string {
  if (minor === null || minor === undefined || Number.isNaN(minor)) return ''

  return new Intl.NumberFormat('tr-TR', {
    style: 'currency',
    currency,
    minimumFractionDigits: MINOR_SCALE,
  }).format(minor / 10 ** MINOR_SCALE)
}

/** Minor units → a bare editable number, with no currency symbol. */
export function minorToInput(minor: number | null | undefined): string {
  if (minor === null || minor === undefined || Number.isNaN(minor)) return ''

  return (minor / 10 ** MINOR_SCALE).toFixed(MINOR_SCALE).replace('.', ',')
}

/**
 * What the seller typed → minor units, or null if it is not a number at all.
 *
 * Returns null rather than 0 for unparseable input: treating "abc" as free would put
 * a zero-price listing in the catalogue, and the caller needs to be able to tell the
 * difference between "empty" and "wrong".
 */
export function inputToMinor(raw: string | number | null | undefined): number | null {
  if (raw === null || raw === undefined) return null
  if (typeof raw === 'number') return Math.round(raw * 10 ** MINOR_SCALE)

  const trimmed = raw.trim()
  if (trimmed === '') return null

  const normalised = normaliseSeparators(trimmed)
  if (!/^-?\d+(\.\d+)?$/.test(normalised)) return null

  // Rounded rather than truncated, and only ever at this one boundary: three decimal
  // places typed into a two-decimal currency has to resolve somehow, and rounding
  // half-up is what a seller expects from a price field.
  return Math.round(Number(normalised) * 10 ** MINOR_SCALE)
}

/**
 * Reduces either convention to a plain decimal.
 *
 * The rule is the same one the API's Money::fromDecimalString uses: whichever
 * separator appears last is the decimal one, because no locale puts a grouping
 * separator after the decimal point.
 */
function normaliseSeparators(value: string): string {
  const cleaned = value.replace(/[\s ₺]/g, '')
  const lastComma = cleaned.lastIndexOf(',')
  const lastDot = cleaned.lastIndexOf('.')

  if (lastComma === -1 && lastDot === -1) return cleaned

  if (lastComma > lastDot) {
    return cleaned.replace(/\./g, '').replace(',', '.')
  }

  return cleaned.replace(/,/g, '')
}

/**
 * Basis points → the percentage a human reads. 2000 bps is "20", 1685 is "16,85".
 *
 * Turkish decimals, because this ends up next to a Turkish price. Trailing zeros are
 * dropped so a VAT rate reads "20" rather than "20,00".
 */
export function bpsToPercent(bps: number): string {
  return (bps / 100).toLocaleString('tr-TR', { maximumFractionDigits: 2 })
}

/**
 * Basis points → a whole percentage, for badges.
 *
 * A discount badge is a claim about how good the offer is, not a figure anybody
 * reconciles: "%17 indirim" reads as a discount, "%16,85 indirim" reads as an
 * accounting artefact. The exact basis points stay on the wire for anything that
 * actually computes with them.
 */
export function bpsToWholePercent(bps: number): string {
  return Math.round(bps / 100).toLocaleString('tr-TR')
}

/** Millimetres → centimetres for display, since nobody shops for a 2200 mm sofa. */
export function mmToCm(mm: number | null | undefined): string {
  if (mm === null || mm === undefined) return '—'

  return `${(mm / 10).toLocaleString('tr-TR', { maximumFractionDigits: 1 })} cm`
}
