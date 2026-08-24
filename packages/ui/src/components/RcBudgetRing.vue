<script setup lang="ts">
/**
 * Budget donut.
 *
 * The design spec calls for ring visualisation on budget surfaces (§12), and the
 * approved references show it repeatedly. Built as one component so the storefront,
 * the seller portal and the admin all draw it identically.
 *
 * Pure SVG: no chart library for a single arc, and it stays crisp at any size.
 */
const props = withDefaults(
  defineProps<{
    /** 0–100. Values outside the range are clamped rather than drawn incorrectly. */
    percent: number
    size?: number
    thickness?: number
    label?: string
    caption?: string
  }>(),
  { size: 168, thickness: 14, label: undefined, caption: undefined },
)

const clamped = computed(() => Math.min(100, Math.max(0, props.percent)))
const radius = computed(() => (props.size - props.thickness) / 2)
const circumference = computed(() => 2 * Math.PI * radius.value)
const dash = computed(() => (clamped.value / 100) * circumference.value)
</script>

<template>
  <div class="relative inline-flex items-center justify-center">
    <svg
      :width="size"
      :height="size"
      :viewBox="`0 0 ${size} ${size}`"
      role="img"
      :aria-label="`Bütçenin %${Math.round(clamped)} kadarı kullanıldı`"
    >
      <circle
        :cx="size / 2"
        :cy="size / 2"
        :r="radius"
        fill="none"
        stroke="var(--rc-neutral-150)"
        :stroke-width="thickness"
      />
      <circle
        :cx="size / 2"
        :cy="size / 2"
        :r="radius"
        fill="none"
        stroke="var(--rc-brand-gold)"
        :stroke-width="thickness"
        stroke-linecap="round"
        :stroke-dasharray="`${dash} ${circumference}`"
        :transform="`rotate(-90 ${size / 2} ${size / 2})`"
      />
    </svg>

    <div class="absolute text-center">
      <p class="text-2xl font-medium tracking-tight">{{ label ?? `%${Math.round(clamped)}` }}</p>
      <p v-if="caption" class="mt-0.5 text-xs text-muted">{{ caption }}</p>
    </div>
  </div>
</template>
