<script setup lang="ts">
/**
 * The one button in RefConcept.
 *
 * Dark CTA on light surfaces is the approved primary treatment (design spec §4);
 * every other variant is a step down in emphasis, never a different colour family.
 */
withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
    size?: 'sm' | 'md' | 'lg'
    type?: 'button' | 'submit' | 'reset'
    loading?: boolean
    disabled?: boolean
    block?: boolean
    to?: string
  }>(),
  {
    variant: 'primary',
    size: 'md',
    type: 'button',
    loading: false,
    disabled: false,
    block: false,
    to: undefined,
  },
)

const variants = {
  primary: 'bg-charcoal text-inverse hover:bg-neutral-800',
  secondary: 'border border-line-strong text-ink hover:bg-bg-muted',
  ghost: 'text-ink-secondary hover:bg-bg-muted hover:text-ink',
  danger: 'bg-danger text-white hover:bg-danger-strong',
} as const

const sizes = {
  sm: 'px-4 py-2 text-sm',
  md: 'px-6 py-3 text-sm',
  lg: 'px-7 py-3.5 text-base',
} as const
</script>

<template>
  <component
    :is="to ? 'NuxtLink' : 'button'"
    :to="to"
    :type="to ? undefined : type"
    :disabled="to ? undefined : (disabled || loading)"
    class="inline-flex items-center justify-center gap-2 rounded-pill font-medium transition-colors duration-[--rc-duration-fast] disabled:cursor-not-allowed disabled:opacity-50"
    :class="[variants[variant], sizes[size], block ? 'w-full' : '']"
  >
    <svg
      v-if="loading"
      class="size-4 animate-spin"
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
    >
      <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" opacity="0.25" />
      <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
    </svg>
    <slot />
  </component>
</template>
