<script setup lang="ts">
/**
 * A status chip.
 *
 * Every surface in RefConcept shows the same handful of lifecycle states — a seller
 * sees "İncelemede" on their listing, a reviewer sees it in their queue, and an
 * operator sees it in an audit view. Mapping status to colour in each app is how the
 * three drift until "rejected" is red in one place and grey in another, so the map
 * lives here once.
 *
 * Tone is derived from the status code, never from the label: labels are Turkish
 * prose that changes with copy edits.
 */
const props = withDefaults(
  defineProps<{
    status: string
    label?: string
    size?: 'sm' | 'md'
  }>(),
  { label: undefined, size: 'md' },
)

/**
 * Pending states are amber, terminal-good is green, terminal-bad is red, and
 * anything inert is neutral. An unknown status falls through to neutral rather than
 * throwing: a new state added to the API should look plain, not break the page.
 */
const tones: Record<string, string> = {
  draft: 'bg-bg-muted text-ink-secondary',
  archived: 'bg-bg-muted text-ink-secondary',
  withdrawn: 'bg-bg-muted text-ink-secondary',

  pending_review: 'bg-warning-subtle text-warning-strong',
  in_review: 'bg-warning-subtle text-warning-strong',
  submitted: 'bg-warning-subtle text-warning-strong',
  paused: 'bg-warning-subtle text-warning-strong',
  out_of_stock: 'bg-warning-subtle text-warning-strong',

  approved: 'bg-success-subtle text-success-strong',
  active: 'bg-success-subtle text-success-strong',

  rejected: 'bg-danger-subtle text-danger-strong',
  suspended: 'bg-danger-subtle text-danger-strong',
}

const tone = computed(() => tones[props.status] ?? 'bg-bg-muted text-ink-secondary')

const sizing = computed(() =>
  props.size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs',
)
</script>

<template>
  <span class="inline-flex items-center rounded-pill whitespace-nowrap" :class="[tone, sizing]">
    {{ label ?? status }}
  </span>
</template>
