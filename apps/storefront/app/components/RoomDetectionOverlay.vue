<script setup lang="ts">
/**
 * The customer's own photograph, with what the analysis found drawn on it.
 *
 * This exists because "is 4.85 m right?" is a question almost nobody can answer. Nobody knows
 * how wide their living room is; that is why they uploaded a photograph. What they can answer
 * in a second is whether the box is around the window — and when it is around a mirror, every
 * measurement that followed from it is wrong and they can see exactly why.
 *
 * Boxes are normalised 0–1 in the photograph's own coordinates, so the overlay scales with the
 * image at any size and needs nothing to be measured in the browser.
 */
interface Region {
  kind: string
  label: string | null
  box: number[]
}

defineProps<{
  /** A signed link, valid for a few minutes. It is a photograph of somebody's home. */
  url: string
  regions: Region[]
}>()

/**
 * Doors and windows in their own colours, everything else neutral.
 *
 * The same two colours the plan view uses for the same two things, so a customer moving
 * between the screens is not learning a second legend.
 */
function toneOf(kind: string): string {
  switch (kind) {
    case 'window':
      return 'stroke-accent-500'
    case 'door':
    case 'balcony_door':
      return 'stroke-warning'
    default:
      return 'stroke-neutral-400'
  }
}

function nameOf(kind: string): string {
  switch (kind) {
    case 'window':
      return 'Pencere'
    case 'door':
      return 'Kapı'
    case 'balcony_door':
      return 'Balkon kapısı'
    case 'radiator':
      return 'Radyatör'
    case 'column':
      return 'Kolon'
    default:
      return 'Sabit öğe'
  }
}
</script>

<template>
  <div class="relative overflow-hidden rounded-md bg-bg-muted">
    <img :src="url" alt="Odanızın fotoğrafı" class="block w-full">

    <!--
      One SVG over the picture, in its own 0–1 coordinate space.

      `preserveAspectRatio="none"` because the box coordinates are fractions of the image as
      displayed, and the image fills this element exactly — so the overlay stretches with it
      rather than needing the photograph's pixel size, which the browser only knows after the
      image has loaded.
    -->
    <svg
      class="pointer-events-none absolute inset-0 size-full"
      viewBox="0 0 1 1"
      preserveAspectRatio="none"
      aria-hidden="true"
    >
      <rect
        v-for="(region, index) in regions"
        :key="index"
        :x="region.box[0]"
        :y="region.box[1]"
        :width="(region.box[2] ?? 0) - (region.box[0] ?? 0)"
        :height="(region.box[3] ?? 0) - (region.box[1] ?? 0)"
        fill="none"
        stroke-width="0.006"
        :class="toneOf(region.kind)"
      />
    </svg>

    <!--
      The labels are HTML rather than SVG text: inside a stretched viewBox the text would be
      stretched with it, and a label squashed to the shape of somebody's photograph is a label
      that looks like a rendering fault.
    -->
    <span
      v-for="(region, index) in regions"
      :key="`label-${index}`"
      class="pointer-events-none absolute -translate-y-full rounded-sm bg-charcoal/85 px-1.5 py-0.5 text-[11px] whitespace-nowrap text-white"
      :style="{ left: `${(region.box[0] ?? 0) * 100}%`, top: `${(region.box[1] ?? 0) * 100}%` }"
    >
      {{ nameOf(region.kind) }}<template v-if="region.label"> · {{ region.label }}</template>
    </span>
  </div>
</template>
