import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * Reads a single value out of a dotenv file.
 *
 * Deliberately tiny and dependency-free: build scripts need one secret, and the value
 * must never leave this process. Nothing here logs, echoes or serialises the value.
 */
export function readEnvValue(name, file = 'apps/api/.env') {
  const path = resolve(process.cwd(), file)

  let contents
  try {
    contents = readFileSync(path, 'utf8')
  } catch {
    throw new Error(`Cannot read ${file}. Copy ${file}.example first.`)
  }

  for (const line of contents.split(/\r?\n/)) {
    if (!line.startsWith(`${name}=`)) continue

    const value = line.slice(name.length + 1).trim().replace(/^["']|["']$/g, '')

    if (value === '') break

    return value
  }

  throw new Error(
    `${name} is not set in ${file}. Add it there rather than passing it on the command line, `
    + 'so it stays out of shell history and process listings.',
  )
}

/** A safe description of a secret, for logs and error messages. */
export function describeSecret(value) {
  return `${value.length} chars, starts ${value.slice(0, 4)}…`
}
