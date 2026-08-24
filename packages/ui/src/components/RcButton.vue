<script setup lang="ts">
/**
 * The one button in RefConcept.
 *
 * A dark call to action on a light surface is the approved primary treatment
 * (design spec §4). Everything else is a step down in emphasis, never a different
 * colour family.
 *
 * Radius is deliberately soft-rectangular rather than a full pill: the approved
 * reference uses compact rounded rectangles for actions and reserves pills for status
 * chips and filters, which keeps the two readable apart at a glance.
 */
withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'inverse' | 'onDark'
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
  primary: 'bg-charcoal text-white hover:bg-neutral-800',
  secondary: 'border border-line-strong text-ink hover:bg-bg-muted',
  ghost: 'text-ink-secondary hover:bg-bg-muted hover:text-ink',
  danger: 'bg-danger text-white hover:bg-danger-strong',

  // For dark panels: a light button on charcoal, and its quieter outlined sibling.
  inverse: 'bg-white text-ink hover:bg-neutral-100',
  onDark: 'border border-neutral-700 text-white hover:bg-neutral-800',
} as const

const sizes = {
  sm: 'px-4 py-2 text-sm',
  md: 'px-5 py-2.5 text-sm',
  lg: 'px-7 py-3.5 text-[15px]',
} as const
</script>

<template>
  <component
    :is="to ? 'NuxtLink' : 'button'"
    :to="to"
    :type="to ? undefined : type"
    :disabled="to ? undefined : (disabled || loading)"
    class="inline-flex items-center justify-center gap-2 rounded-sm font-medium transition-colors duration-[--rc-duration-fast] disabled:cursor-not-allowed disabled:opacity-50"
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
