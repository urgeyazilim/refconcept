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
import type { LayoutItem, RoomGeometry, RoomOpening } from '~/room3d/types'

definePageMeta({ layout: false })
useHead({ title: 'Oda 3D · laboratuvar' })

const geometry = ref<RoomGeometry>({
  id: 'lab',
  width_mm: 4_850,
  length_mm: 5_200,
  height_mm: 2_720,
  // `?floor=tile` on the URL, so a screenshot can ask for each floor in turn.
  floor: (['wood', 'tile', 'carpet'] as const).find(kind => kind === useRoute().query.floor) ?? 'wood',
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

/**
 * A furnished room, with one of everything the editor treats differently.
 *
 * A rug, because everything is allowed to stand on it and nothing else. A picture at 1.5 m,
 * because anything off the floor shares no space with anything on it. A sideboard against
 * the east wall beside the door, because that is where the clearance rule bites. And a piece
 * with no dimensions at all, which the catalogue is full of and which has to be visible as a
 * placeholder rather than quietly drawn at a guessed size.
 */
const items = ref<LayoutItem[]>([
  item('rug', 'Halı', 'hali', 2_400, 1_700, 20, 2_400, 2_800),
  item('sofa', 'Üçlü kanepe', 'kanepe', 2_200, 900, 820, 2_400, 1_900),
  item('table', 'Orta sehpa', 'sehpa', 900, 900, 400, 2_400, 2_900),
  item('sideboard', 'Konsol', 'konsol', 1_400, 420, 780, 3_900, 4_200, 90),
  item('picture', 'Tablo', 'tablo', 900, 50, 700, 2_400, 120, 0, 1_500),
  { ...item('lamp', 'Zemin lambası (ölçüsüz)', 'aydinlatma', 0, 0, 0, 900, 900), width_mm: null, depth_mm: null, height_mm: null },
])

function item(
  id: string,
  name: string,
  category: string,
  width: number,
  depth: number,
  height: number,
  x: number,
  z: number,
  rotation = 0,
  y = 0,
): LayoutItem {
  return {
    id,
    product_id: id,
    sku_id: id,
    name,
    category,
    position_x_mm: x,
    position_y_mm: y,
    position_z_mm: z,
    rotation_y_deg: rotation,
    locked: false,
    collision_state: 'ok',
    width_mm: width,
    height_mm: height,
    depth_mm: depth,
    image_url: null,
    model_url: null,
  }
}

/** Nothing to save here; seeing what would have been sent is the point of the bench. */
const saved = ref<string>('—')

function onSave(next: LayoutItem[]): void {
  saved.value = next
    .map(entry => `${entry.name}: ${entry.position_x_mm}, ${entry.position_z_mm} @ ${entry.rotation_y_deg}°`)
    .join(' · ')
}
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

      <Room3DScene
        :geometry="geometry"
        :openings="openings"
        :items="items"
        editable
        @save="onSave"
        @move-opening="(id, offset, wall) => { openings = openings.map(opening => (opening.id === id ? { ...opening, offset_mm: offset, wall } : opening)) }"
      />

      <p class="rounded-sm bg-surface p-3 text-xs text-muted">
        Son kaydedilecek yerleşim: {{ saved }}
      </p>

      <div class="grid gap-4 sm:grid-cols-4">
        <label class="block">
          <span class="mb-1.5 block text-sm font-medium">zemin</span>
          <select v-model="geometry.floor" class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm">
            <option value="wood">parke</option>
            <option value="tile">fayans</option>
            <option value="carpet">halı</option>
          </select>
        </label>
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
