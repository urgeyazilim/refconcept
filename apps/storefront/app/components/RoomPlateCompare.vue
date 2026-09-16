<script setup lang="ts">
/**
 * Before and after: the photograph, and the same photograph with the furniture gone.
 *
 * A slider rather than two pictures side by side, because the point is that everything
 * except the furniture is the same — the window, the radiator, the light on the floor — and
 * sliding a line across the picture shows that in a way two thumbnails cannot.
 */
defineProps<{
  before: string
  after: string
}>()

/** Where the dividing line is, as a percentage of the width. Starts in the middle. */
const split = ref(50)
</script>

<template>
  <!--
    Sized by the photograph but never taller than the stage: a portrait photograph at full
    width pushed the whole step below the window, and the product owner had to scroll to
    see the bottom of it. The container shrinks to the picture, so the split is still a
    percentage of the picture.
  -->
  <div class="relative w-fit max-w-full select-none overflow-hidden rounded-md border border-line bg-bg-muted">
    <img :src="before" alt="Odanın fotoğrafı" class="block h-auto max-h-[calc(100vh-22rem)] max-w-full" draggable="false">

    <!-- The emptied room, clipped to the left of the line. -->
    <div class="absolute inset-0 overflow-hidden" :style="{ width: `${split}%` }">
      <img :src="after" alt="Eşyaları kaldırılmış oda" class="block h-full w-auto max-w-none" :style="{ width: `${10000 / split}%` }" draggable="false">
    </div>

    <div class="pointer-events-none absolute inset-y-0 w-0.5 bg-white shadow" :style="{ left: `${split}%` }" />

    <span class="pointer-events-none absolute left-3 top-3 rounded-pill bg-charcoal/80 px-2.5 py-1 text-[11px] text-white">Boş oda</span>
    <span class="pointer-events-none absolute right-3 top-3 rounded-pill bg-charcoal/80 px-2.5 py-1 text-[11px] text-white">Fotoğraf</span>

    <input
      v-model.number="split"
      type="range"
      min="2"
      max="98"
      aria-label="Öncesi ve sonrası arasındaki çizgi"
      class="absolute inset-x-0 bottom-0 h-full w-full cursor-ew-resize opacity-0"
    >
  </div>
</template>
