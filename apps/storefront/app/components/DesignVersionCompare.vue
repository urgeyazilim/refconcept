<script setup lang="ts">
/**
 * Two renders of the same room, held up against each other (K26).
 *
 * Side by side is the honest default: two decisions, two pictures, no trick. The wipe is
 * there for the question side by side cannot answer — "did the walls move?" — because both
 * renders started from the same plate and, if the check did its job, they line up.
 */
defineProps<{
  left: { src: string, label: string }
  right: { src: string, label: string }
}>()

const mode = ref<'side' | 'wipe'>('side')
</script>

<template>
  <div>
    <div class="mb-3 flex items-center gap-1 text-xs">
      <button
        type="button"
        class="rounded-pill px-3 py-1 transition-colors"
        :class="mode === 'side' ? 'bg-charcoal text-white' : 'text-ink-secondary hover:bg-bg-muted'"
        @click="mode = 'side'"
      >
        Yan yana
      </button>
      <button
        type="button"
        class="rounded-pill px-3 py-1 transition-colors"
        :class="mode === 'wipe' ? 'bg-charcoal text-white' : 'text-ink-secondary hover:bg-bg-muted'"
        @click="mode = 'wipe'"
      >
        Üst üste kaydır
      </button>
    </div>

    <div v-if="mode === 'side'" class="grid gap-3 sm:grid-cols-2">
      <figure v-for="side in [left, right]" :key="side.label" class="overflow-hidden rounded-md bg-charcoal">
        <!--
          Contained rather than covered, at the shape a render is.
          
          Both sides here are renders, and a 3:2 picture cropped into a 16:10 box loses its
          top and bottom — which on the design screen is the ceiling and the floor of a room
          somebody paid to see. Nothing here is a thumbnail; it is the work.
        -->
        <img :src="side.src" :alt="side.label" class="aspect-[3/2] w-full object-contain" draggable="false">
        <figcaption class="px-3 py-2 text-xs text-white/80">{{ side.label }}</figcaption>
      </figure>
    </div>

    <RcBeforeAfter
      v-else
      :before-src="left.src"
      :after-src="right.src"
      :before-label="left.label"
      :after-label="right.label"
    />
  </div>
</template>
