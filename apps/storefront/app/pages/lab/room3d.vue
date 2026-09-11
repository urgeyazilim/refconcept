<script setup lang="ts">
/**
 * A bench for the 3D room, with no API behind it.
 *
 * The geometry here is the one from the product storyboard — 4.85 × 5.20 × 2.72 m, a 1.80 m
 * window and a 0.90 m door — so what appears on screen can be checked against the picture
 * the feature was specified with rather than against a guess.
 *
 * It exists because the alternative is proving the scene through the whole stack: upload a
 * photograph, run an analysis, confirm measurements, and only then find out the east wall is
 * inside out. Openings are the part most likely to be wrong and the part hardest to see in a
 * screenshot, so they are pinned to known numbers here and varied by hand.
 */
import type { RoomGeometry, RoomOpening } from '~/room3d/types'

definePageMeta({ layout: false })
useHead({ title: 'Oda 3D · laboratuvar' })

const geometry = ref<RoomGeometry>({
  id: 'lab',
  width_mm: 4_850,
  length_mm: 5_200,
  height_mm: 2_720,
})

/**
 * One opening on each wall, so a mirrored axis is obvious at a glance.
 *
 * If the offsets ran the wrong way along a wall, three of these would still look plausible
 * on their own. Together they cannot: the window near the west end of the north wall and the
 * door near the north end of the east wall are on opposite sides of the room, and a reversed
 * axis swaps them.
 */
const openings = ref<RoomOpening[]>([
  {
    id: 'w1',
    type: 'window',
    wall: 'north',
    offset_mm: 720,
    width_mm: 1_800,
    height_mm: 1_600,
    sill_height_mm: 900,
  },
  {
    id: 'd1',
    type: 'door',
    wall: 'east',
    offset_mm: 400,
    width_mm: 900,
    height_mm: 2_100,
    sill_height_mm: 0,
  },
  {
    id: 'w2',
    type: 'window',
    wall: 'south',
    offset_mm: 2_600,
    width_mm: 1_200,
    height_mm: 1_400,
    sill_height_mm: 1_000,
  },
])
</script>

<template>
  <div class="min-h-screen bg-bg p-8">
    <div class="mx-auto max-w-5xl space-y-6">
      <header>
        <h1 class="text-xl font-medium">Oda 3D — laboratuvar</h1>
        <p class="mt-1 text-sm text-muted">
          Storyboard'daki ölçüler: 4.85 × 5.20 m, tavan 2.72 m. Kuzeyde pencere, doğuda kapı,
          güneyde ikinci pencere — üçü birden, eksen ters çevrilmiş olsaydı yerleri değişirdi.
        </p>
      </header>

      <Room3DScene :geometry="geometry" :openings="openings" />

      <div class="grid gap-4 sm:grid-cols-3">
        <label v-for="axis in (['width_mm', 'length_mm', 'height_mm'] as const)" :key="axis" class="block">
          <span class="mb-1.5 block text-sm font-medium">{{ axis.replace('_mm', '') }} (mm)</span>
          <input
            v-model.number="geometry[axis]"
            type="number"
            step="50"
            class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
          >
        </label>
      </div>
    </div>
  </div>
</template>
