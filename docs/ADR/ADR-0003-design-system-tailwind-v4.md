# ADR-0003 — Design system delivery: shared token package + Tailwind v4 theme bridge

- Status: Accepted
- Date: 2026-08-23
- Phase: 0

## Context

`21_DESIGN_SYSTEM_UI_SPEC.md` and `22_SCREEN_BLUEPRINTS_INFORMATION_ARCHITECTURE.md`
require that Storefront, Seller Portal and Super Admin come from one visual family, with
an approved palette (Charcoal / Warm Gray / Sand / Taupe / Gold) and an explicit
anti-pattern: "harsh SaaS-blue generic look".

A default Tailwind installation ships a blue-centric palette whose utilities (`bg-blue-600`,
`text-slate-500`) are exactly the drift the spec forbids, and three separately configured
apps would diverge within a phase or two.

## Decision

- One workspace package, `@refconcept/ui`, owns the tokens.
- Tokens exist in three synchronised forms:
  - `tokens.ts` — typed values for TS/Vue logic (charts, canvas, inline styles).
  - `tokens.css` — CSS custom properties (`--rc-*`) that are the runtime source of truth.
  - `theme.css` — a Tailwind v4 `@theme` block mapping those properties onto utilities.
- `theme.css` starts with `--color-*: initial`, which **removes Tailwind's default palette**.
  `bg-blue-500` stops existing; only RefConcept colours can be typed.
- Role aliases (`bg-surface`, `text-muted`, `border-line`, `bg-bg-muted`) resolve to the same
  custom properties as hand-written CSS, so utilities and component CSS cannot drift apart.
- Tailwind v4 via `@tailwindcss/vite` (no `tailwind.config.js`), matching the CSS-first
  configuration model and keeping the theme in the same file family as the tokens.

## Alternatives considered

- **Tailwind v3 + shared JS preset.** Familiar, but keeps a parallel JS config to maintain
  and does not naturally consume CSS custom properties.
- **Plain CSS modules, no Tailwind.** Maximum control, far slower for three apps and loses
  the constraint benefit of a closed palette.
- **Per-app configuration.** Rejected: guarantees divergence, and the spec explicitly
  requires one design family.

## Consequences

- Adding a colour is a deliberate act in one file, reviewable against the design refs.
- Apps import three stylesheets in a fixed order (`tailwindcss`, `tokens.css`, `theme.css`).
- A future dark/operational theme is a custom-property override (`[data-rc-theme]`), not a
  second palette.

## Test plan

- A lint rule / CI grep fails the build when a raw hex colour outside the token set appears
  in app source.
- Visual acceptance in Phase 20 compares implemented screens against `design_refs/`.
