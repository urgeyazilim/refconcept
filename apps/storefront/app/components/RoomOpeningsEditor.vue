<script setup lang="ts">
import type { RoomConstraintItem } from '@refconcept/ui/types'
import type { RoomOpening, WallName } from '~/room3d/types'

/**
 * The doors and windows, corrected by hand.
 *
 * The reading guesses which wall a door is on and how far along; it has no compass and it
 * is often a wall out. Rather than trust it, the customer fixes it here in seconds: the room
 * from above, each opening a handle on its wall — drag it along, move it to another wall
 * from the list, add one with a click and drag it into place. Every change is saved as it
 * happens and marked as the customer's own (K6).
 */
const props = defineProps<{
  base: string
  geometry: { width_mm: number, length_mm: number, height_mm: number }
  constraints: RoomConstraintItem[]
  canEdit: boolean
}>()

const emit = defineEmits<{ (event: 'changed'): void }>()

const api = useApi()

const OPENING_TYPES = ['door', 'balcony_door', 'window'] as const
const TYPE_LABELS: Record<string, string> = { door: 'Kapı', balcony_door: 'Balkon kapısı', window: 'Pencere' }
const WALLS: Array<{ value: WallName, label: string }> = [
  { value: 'north', label: 'Üst duvar' },
  { value: 'east', label: 'Sağ duvar' },
  { value: 'south', label: 'Alt duvar' },
  { value: 'west', label: 'Sol duvar' },
]

/** Doors and windows only; a radiator is a note the plan draws differently. */
const openings = computed<RoomOpening[]>(() =>
  props.constraints
    .filter(item => (OPENING_TYPES as readonly string[]).includes(item.type))
    .map(item => ({
      id: item.id,
      type: item.type,
      wall: (item.wall as WallName | null) ?? null,
      offset_mm: item.offset_mm,
      width_mm: item.width_mm,
      height_mm: item.height_mm,
      sill_height_mm: item.sill_height_mm,
    })),
)

const notice = ref<string | null>(null)
const busy = ref<string | null>(null)

async function patch(id: string, changes: Record<string, number | string | null>) {
  busy.value = id
  notice.value = null

  try {
    await api.patch(`${props.base}/constraints/${id}`, { ...changes, notes: 'Sizin düzelttiğiniz.' })
    emit('changed')
  }
  catch (error) {
    notice.value = error instanceof ApiError ? error.message : 'Değişiklik kaydedilemedi.'
  }
  finally {
    busy.value = null
  }
}

function wallLength(wall: WallName): number {
  return wall === 'north' || wall === 'south' ? props.geometry.width_mm : props.geometry.length_mm
}

/**
 * Adds a door or a window on a wall with room for it, in its middle, and lets the customer
 * drag it from there. Sensible sizes: a door 900 × 2100, a window 1200 × 1400 on a 900 sill.
 */
async function add(type: 'door' | 'window') {
  busy.value = 'new'
  notice.value = null

  const width = type === 'door' ? 900 : 1_200
  const wall = WALLS.map(entry => entry.value).find(candidate => wallLength(candidate) >= width + 600 && openings.value.every(opening => opening.wall !== candidate)) ?? 'north'

  try {
    await api.post(`${props.base}/constraints`, {
      type,
      label: TYPE_LABELS[type],
      wall,
      offset_mm: Math.max(0, Math.round((wallLength(wall) - width) / 2)),
      width_mm: width,
      height_mm: type === 'door' ? 2_100 : 1_400,
      sill_height_mm: type === 'door' ? 0 : 900,
      notes: 'Sizin eklediğiniz.',
    })

    emit('changed')
  }
  catch (error) {
    notice.value = error instanceof ApiError ? error.message : 'Eklenemedi.'
  }
  finally {
    busy.value = null
  }
}

async function remove(id: string) {
  busy.value = id
  notice.value = null

  try {
    await api.delete(`${props.base}/constraints/${id}`)
    emit('changed')
  }
  catch (error) {
    notice.value = error instanceof ApiError ? error.message : 'Kaldırılamadı.'
  }
  finally {
    busy.value = null
  }
}

const cm = (mm: number | null): string => (mm === null ? '' : String(Math.round(mm / 10)))

</script>

<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h3 class="font-medium">Kapılar ve pencereler</h3>
        <p class="mt-1 max-w-[60ch] text-sm leading-relaxed text-ink-secondary">
          Fotoğraftan okuduklarımı buraya koydum; yanlışsa planda tut, doğru duvara sürükle.
          Yenisini + Kapı / + Pencere ile ekle, sonra yerine taşı. Kapının önüne bir şey koymam.
        </p>
      </div>

      <div v-if="canEdit" class="flex items-center gap-2">
        <button type="button" class="rounded-pill border border-line px-3 py-1.5 text-xs text-ink-secondary hover:bg-bg-muted disabled:opacity-40" :disabled="busy !== null" @click="add('door')">
          + Kapı
        </button>
        <button type="button" class="rounded-pill border border-line px-3 py-1.5 text-xs text-ink-secondary hover:bg-bg-muted disabled:opacity-40" :disabled="busy !== null" @click="add('window')">
          + Pencere
        </button>
      </div>
    </div>

    <RcAlert v-if="notice" tone="danger" class="mt-4">{{ notice }}</RcAlert>

    <div class="mt-4 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
      <!-- The room from above; each door and window is a handle on its wall. -->
      <div>
        <div class="overflow-hidden rounded-md border border-line bg-surface p-3">
          <RoomPlanSvg
            :geometry="{ id: 'room', width_mm: geometry.width_mm, length_mm: geometry.length_mm, height_mm: geometry.height_mm }"
            :openings="openings"
            :items="[]"
            :states="new Map()"
            :editable-openings="canEdit"
            @move-opening="(id, offsetMm, wall) => patch(id, { offset_mm: offsetMm, wall })"
            @resize-opening="(id, offsetMm, widthMm) => patch(id, { offset_mm: offsetMm, width_mm: widthMm })"
          />
        </div>
        <p class="mt-2 text-center text-[11px] leading-relaxed text-muted">Kapı ya da pencereyi tutup kaydır, başka bir duvara da bırakabilirsin; uçlarındaki noktalardan genişlet ya da daralt.</p>
      </div>

      <ul class="space-y-2">
        <li v-if="openings.length === 0" class="rounded-md bg-bg-muted p-4 text-sm text-ink-secondary">
          Henüz kapı ya da pencere yok. "+ Kapı" ya da "+ Pencere" ile ekle, sonra planda yerine sürükle.
        </li>

        <li
          v-for="opening in openings"
          :key="opening.id"
          class="rounded-md border border-line p-3"
          :class="{ 'opacity-60': busy === opening.id }"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-medium">{{ TYPE_LABELS[opening.type] ?? opening.type }}<span v-if="opening.width_mm"> · {{ cm(opening.width_mm) }} cm</span></p>
            <button v-if="canEdit" type="button" class="text-xs text-danger hover:underline" @click="remove(opening.id)">Kaldır</button>
          </div>

          <p class="mt-1 text-xs text-muted">
            {{ WALLS.find(wall => wall.value === opening.wall)?.label ?? 'Duvarı belli değil' }}
            <span v-if="opening.offset_mm !== null"> · köşeden {{ cm(opening.offset_mm) }} cm</span>
            — planda tutup taşı
          </p>
        </li>
      </ul>
    </div>
  </div>
</template>
