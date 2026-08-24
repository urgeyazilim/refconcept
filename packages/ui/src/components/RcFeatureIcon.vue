<script setup lang="ts">
/**
 * An icon in a tinted plate.
 *
 * Hairline grey icons on a warm background disappear — the first pass rendered them
 * at 28px with a 1.5px stroke in `text-ink-secondary` and they read as smudges rather
 * than iconography. A tinted plate gives the mark a footprint and enough contrast to
 * be seen at a glance, which is what the approved references do.
 */
withDefaults(
  defineProps<{
    /** Inline SVG path data — thin outline icons only (design spec §6). */
    icon: string
    tone?: 'accent' | 'neutral' | 'dark'
    size?: 'sm' | 'md' | 'lg'
  }>(),
  { tone: 'accent', size: 'md' },
)

const tones = {
  accent: 'bg-accent-100 text-accent-800',
  neutral: 'bg-neutral-150 text-ink',
  dark: 'bg-charcoal text-white',
} as const

const plates = {
  sm: 'size-10 rounded-sm',
  md: 'size-12 rounded-md',
  lg: 'size-14 rounded-md',
} as const

const glyphs = {
  sm: 'size-[18px]',
  md: 'size-[22px]',
  lg: 'size-6',
} as const
</script>

<template>
  <span class="inline-grid place-items-center" :class="[tones[tone], plates[size]]" aria-hidden="true">
    <svg class="rc-icon" :class="glyphs[size]" viewBox="0 0 24 24">
      <path :d="icon" />
    </svg>
  </span>
</template>
