<script setup lang="ts">
/**
 * Inline feedback. Semantic colours stay muted so an error never breaks the
 * neutral palette (design spec §4).
 */
withDefaults(
  defineProps<{
    tone?: 'info' | 'success' | 'warning' | 'danger'
    title?: string
  }>(),
  { tone: 'info', title: undefined },
)

const tones = {
  info: 'bg-bg-muted text-ink-secondary',
  success: 'bg-success-subtle text-success-strong',
  warning: 'bg-warning-subtle text-warning-strong',
  danger: 'bg-danger-subtle text-danger-strong',
} as const
</script>

<template>
  <div class="rounded-md px-4 py-3 text-sm" :class="tones[tone]" role="status">
    <p v-if="title" class="mb-0.5 font-medium">{{ title }}</p>
    <slot />
  </div>
</template>
