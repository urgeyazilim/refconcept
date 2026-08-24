#!/usr/bin/env node
/**
 * Design token guard.
 *
 * 21_DESIGN_SYSTEM_UI_SPEC.md forbids drifting into "generic blue SaaS branding".
 * A palette only holds if violations fail the build, so this script scans frontend
 * source for two things:
 *
 *   1. raw hex colours that are not part of the approved token set
 *   2. Tailwind utility classes referencing palettes we deleted (blue/indigo/slate/...)
 *
 * Run: node scripts/check-design-tokens.mjs
 */

import { readdir, readFile } from 'node:fs/promises'
import { join, relative, extname } from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = fileURLToPath(new URL('..', import.meta.url))

const SCAN_DIRS = ['apps/storefront', 'apps/seller-portal', 'apps/admin-panel', 'packages/ui/src/components']
const SCAN_EXTENSIONS = new Set(['.vue', '.ts', '.tsx', '.js', '.mjs', '.css', '.scss'])
const IGNORED_DIRS = new Set(['node_modules', '.nuxt', '.output', 'dist', '.git', 'coverage', 'public'])

/** The only literal colours allowed outside packages/ui/src/tokens. */
const ALLOWED_HEX = new Set(
  [
    '#111111', '#f5f3f0', '#dcce86', '#a89e8e', '#c9a86a',
    '#ffffff', '#fcfbfa', '#f8f6f3', '#eeebe6', '#e5e1da', '#d4cec5',
    '#b7b0a5', '#8a8175', '#605a52', '#3b3733', '#22201e',
    '#fbf7ef', '#f4ebd8', '#e9d8b4', '#dcc48d', '#d0b478', '#b08f52',
    '#8c7141', '#665231', '#463823',
    '#eef2e6', '#6e8c4b', '#4e6634',
    '#fbf1e2', '#c08a3e', '#8e6427',
    '#f9ebe7', '#b4573f', '#8a3f2c',
    '#f0efec',
    '#000', '#fff', '#000000',
  ].map((c) => c.toLowerCase()),
)

const FORBIDDEN_PALETTES = [
  'slate', 'gray', 'zinc', 'stone', 'red', 'orange', 'amber', 'yellow', 'lime',
  'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple',
  'fuchsia', 'pink', 'rose',
]

const HEX_PATTERN = /#[0-9a-fA-F]{3,8}\b/g
const UTILITY_PATTERN = new RegExp(
  `\b(?:bg|text|border|ring|from|to|via|fill|stroke|shadow|outline|decoration|divide|accent|caret|placeholder)-(?:${FORBIDDEN_PALETTES.join('|')})-\d{2,3}\b`,
  'g',
)

async function* walk(dir) {
  let entries
  try {
    entries = await readdir(dir, { withFileTypes: true })
  } catch {
    return // directory not scaffolded yet
  }
  for (const entry of entries) {
    if (entry.name.startsWith('.') && entry.name !== '.storybook') continue
    const full = join(dir, entry.name)
    if (entry.isDirectory()) {
      if (IGNORED_DIRS.has(entry.name)) continue
      yield* walk(full)
    } else if (SCAN_EXTENSIONS.has(extname(entry.name))) {
      yield full
    }
  }
}

const violations = []

for (const scanDir of SCAN_DIRS) {
  for await (const file of walk(join(repoRoot, scanDir))) {
    const source = await readFile(file, 'utf8')
    const lines = source.split(/\r?\n/)

    lines.forEach((line, index) => {
      for (const match of line.matchAll(HEX_PATTERN)) {
        const hex = match[0].toLowerCase()
        // Ignore 8-digit alpha variants of allowed colours and short-form duplicates.
        if (ALLOWED_HEX.has(hex) || ALLOWED_HEX.has(hex.slice(0, 7))) continue
        violations.push({
          file: relative(repoRoot, file),
          line: index + 1,
          kind: 'raw-hex',
          detail: `${match[0]} is not an approved RefConcept colour`,
        })
      }

      for (const match of line.matchAll(UTILITY_PATTERN)) {
        violations.push({
          file: relative(repoRoot, file),
          line: index + 1,
          kind: 'foreign-palette',
          detail: `${match[0]} uses a deleted Tailwind palette; use a RefConcept role or accent token`,
        })
      }
    })
  }
}

if (violations.length > 0) {
  console.error(`\n✖ Design token guard failed — ${violations.length} violation(s):\n`)
  for (const v of violations) {
    console.error(`  ${v.file}:${v.line}  [${v.kind}]  ${v.detail}`)
  }
  console.error('\nApproved palette lives in packages/ui/src/tokens/. See docs/ADR/ADR-0003.\n')
  process.exit(1)
}

console.log('✔ Design token guard passed — no foreign colours found.')
