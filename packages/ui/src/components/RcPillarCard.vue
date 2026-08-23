<script setup lang="ts">
defineProps<{
  title: string
  description: string
  /** Inline SVG path data — thin outline icons only (design spec §6). */
  icon: string
}>()
</script>

<template>
  <article
    class="rc-card group h-full p-6 transition-shadow duration-[--rc-duration-normal] hover:shadow-md"
  >
    <span
      class="mb-5 grid size-11 place-items-center rounded-md bg-accent-50 text-accent-700"
      aria-hidden="true"
    >
      <svg class="rc-icon size-5" viewBox="0 0 24 24">
        <path :d="icon" />
      </svg>
    </span>
    <h3 class="mb-2 text-lg font-medium">{{ title }}</h3>
    <p class="text-sm leading-relaxed text-ink-secondary">{{ description }}</p>
  </article>
</template>
