/**
 * RefConcept design tokens.
 *
 * Source of truth: 21_DESIGN_SYSTEM_UI_SPEC.md + design_refs/brand_colors.jpg.
 * The five brand values are approved and must not be changed without an ADR.
 * Everything else (tints, semantic states) is derived to stay inside the
 * "warm minimal / quiet luxury" language and away from generic SaaS blue.
 */

export const brand = {
  charcoal: '#111111',
  warmGray: '#F5F3F0',
  sand: '#DCCE86',
  taupe: '#A89E8E',
  gold: '#C9A86A',
} as const

/** Warm neutral ramp derived from Charcoal → Warm Gray. Used for text, lines, surfaces. */
export const neutral = {
  0: '#FFFFFF',
  25: '#FDFCFB',
  50: '#F9F7F5',
  100: '#F5F3F0',
  150: '#EFECE7',
  200: '#E6E2DB',
  300: '#D5CFC5',
  400: '#B9B2A6',
  500: '#A89E8E',
  600: '#857C6E',
  700: '#5F594F',
  800: '#3A3630',
  900: '#211F1C',
  950: '#111111',
} as const

/** Gold accent ramp — charts, budget rings, tags, highlights. */
export const accent = {
  50: '#FBF7EF',
  100: '#F4EBD8',
  200: '#E9D8B4',
  300: '#DCC48D',
  400: '#D0B478',
  500: '#C9A86A',
  600: '#B08F52',
  700: '#8C7141',
  800: '#665231',
  900: '#463823',
} as const

/**
 * Semantic states. Muted olive / warm amber / softened terracotta, per spec §4.
 * Deliberately desaturated so alerts never break the neutral base.
 */
export const semantic = {
  success: { subtle: '#EEF2E6', base: '#6E8C4B', strong: '#4E6634', on: '#FFFFFF' },
  warning: { subtle: '#FBF1E2', base: '#C08A3E', strong: '#8E6427', on: '#FFFFFF' },
  danger: { subtle: '#F9EBE7', base: '#B4573F', strong: '#8A3F2C', on: '#FFFFFF' },
  info: { subtle: '#F0EFEC', base: '#5F594F', strong: '#3A3630', on: '#FFFFFF' },
} as const

/** Spacing rhythm: 4 / 8 / 12 / 16 / 24 / 32 / 48 / 64 (spec §7.1). */
export const spacing = {
  0: '0px',
  1: '4px',
  2: '8px',
  3: '12px',
  4: '16px',
  6: '24px',
  8: '32px',
  12: '48px',
  16: '64px',
  20: '80px',
  24: '96px',
  32: '128px',
} as const

/** Radii (spec §7.2). Cards and shells are noticeably rounded. */
export const radius = {
  none: '0px',
  sm: '10px',
  md: '16px',
  lg: '20px',
  xl: '28px',
  '2xl': '36px',
  pill: '9999px',
} as const

/** Soft "elevated paper" shadows — never floating glass (spec §7.3). */
export const shadow = {
  none: 'none',
  xs: '0 1px 2px 0 rgba(17, 17, 17, 0.04)',
  sm: '0 2px 8px -2px rgba(17, 17, 17, 0.06), 0 1px 2px 0 rgba(17, 17, 17, 0.04)',
  md: '0 8px 24px -8px rgba(17, 17, 17, 0.10), 0 2px 6px -2px rgba(17, 17, 17, 0.05)',
  lg: '0 20px 48px -16px rgba(17, 17, 17, 0.14), 0 4px 12px -4px rgba(17, 17, 17, 0.06)',
  focus: '0 0 0 3px rgba(201, 168, 106, 0.35)',
} as const

/** Satoshi preferred, Inter fallback, then system (spec §5). */
export const typography = {
  fontFamily: {
    sans: "'Satoshi', 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
    display: "'Satoshi', 'Inter', system-ui, sans-serif",
    mono: "'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace",
  },
  fontSize: {
    xs: ['12px', { lineHeight: '18px', letterSpacing: '0.01em' }],
    sm: ['14px', { lineHeight: '22px' }],
    base: ['16px', { lineHeight: '26px' }],
    lg: ['18px', { lineHeight: '28px' }],
    xl: ['20px', { lineHeight: '30px' }],
    '2xl': ['24px', { lineHeight: '34px', letterSpacing: '-0.01em' }],
    '3xl': ['30px', { lineHeight: '40px', letterSpacing: '-0.015em' }],
    '4xl': ['38px', { lineHeight: '46px', letterSpacing: '-0.02em' }],
    '5xl': ['48px', { lineHeight: '56px', letterSpacing: '-0.025em' }],
    '6xl': ['60px', { lineHeight: '66px', letterSpacing: '-0.03em' }],
    '7xl': ['72px', { lineHeight: '78px', letterSpacing: '-0.03em' }],
  },
  fontWeight: {
    light: '300',
    normal: '400',
    medium: '500',
    semibold: '600',
    bold: '700',
  },
} as const

/** Layout containers — generous side padding, wide editorial sections (spec §7.1). */
export const layout = {
  container: {
    sm: '640px',
    md: '768px',
    lg: '1024px',
    xl: '1280px',
    '2xl': '1440px',
    content: '1200px',
    prose: '720px',
  },
  gutter: {
    mobile: '20px',
    tablet: '32px',
    desktop: '48px',
  },
} as const

/** Calm, unhurried motion. No bouncy or gamified easing. */
export const motion = {
  duration: {
    instant: '80ms',
    fast: '150ms',
    normal: '240ms',
    slow: '400ms',
    deliberate: '640ms',
  },
  easing: {
    standard: 'cubic-bezier(0.32, 0.72, 0, 1)',
    entrance: 'cubic-bezier(0.16, 1, 0.3, 1)',
    exit: 'cubic-bezier(0.4, 0, 1, 1)',
  },
} as const

export const zIndex = {
  base: 0,
  raised: 10,
  sticky: 100,
  drawer: 200,
  overlay: 300,
  modal: 400,
  popover: 500,
  toast: 600,
} as const

export const breakpoints = {
  sm: '640px',
  md: '768px',
  lg: '1024px',
  xl: '1280px',
  '2xl': '1536px',
} as const

export const tokens = {
  brand,
  neutral,
  accent,
  semantic,
  spacing,
  radius,
  shadow,
  typography,
  layout,
  motion,
  zIndex,
  breakpoints,
} as const

export type RefConceptTokens = typeof tokens
