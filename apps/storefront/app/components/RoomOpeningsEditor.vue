<script setup lang="ts">
import type { RoomConstraintItem } from '@refconcept/ui/types'
import { OPENING_TYPES, type OpeningKind, TYPE_LABELS, describeKind, hasSwing, hingeIsLeft, kindsFor, opensIn, otherJamb, otherWay, swingOf, variantOf } from '~/room3d/openings'
import type { RoomOpening, WallName } from '~/room3d/types'

/**
 * The doors and windows, corrected by hand.
 *
 * The reading guesses which wall a door is on and how far along; it has no compass and it
 * is often a wall out. Rather than trust it, the customer fixes it here in seconds: the room
 * from above, each opening a handle on its wall — drag it along, move it to another wall,
 * add one with a click and drag it into place, say it is a double rather than a single.
 * Every change is saved as it happens and marked as the customer's own (K6).
 */
const props = defineProps<{
  base: string
  geometry: { width_mm: number, length_mm: number, height_mm: number }
  constraints: RoomConstraintItem[]
  canEdit: boolean
}>()

const emit = defineEmits<{ (event: 'changed'): void }>()

const api = useApi()

const WALLS: Array<{ value: WallName, label: string }> = [
  { value: 'north', label: 'Üst duvar' },
  { value: 'east', label: 'Sağ duvar' },
  { value: 'south', label: 'Alt duvar' },
  { value: 'west', label: 'Sol duvar' },
]

/** What can be put on a wall, grouped as the customer thinks of them. */
const PALETTE = OPENING_TYPES.map(type => ({ type, label: TYPE_LABELS[type], kinds: kindsFor(type) }))

/** Doors and windows only; a radiator is a note the plan draws differently. */
const openings = computed<RoomOpening[]>(() =>
  props.constraints
    .filter(item => (OPENING_TYPES as readonly string[]).includes(item.type))
    .map(item => ({
      id: item.id,
      type: item.type,
      variant: item.variant,
      swing: item.swing,
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
 * Adds a door or a window of the chosen kind on a wall with room for it, in its middle, at
 * the size such a thing usually is, and lets the customer drag it from there.
 */
async function add(kind: OpeningKind) {
  busy.value = 'new'
  notice.value = null

  const width = kind.width_mm
  const wall = WALLS.map(entry => entry.value).find(candidate => wallLength(candidate) >= width + 600 && openings.value.every(opening => opening.wall !== candidate)) ?? 'north'

  try {
    await api.post(`${props.base}/constraints`, {
      type: kind.type,
      variant: kind.variant,
      label: describeKind({ type: kind.type, variant: kind.variant, width_mm: width, sill_height_mm: kind.sill_height_mm }),
      wall,
      offset_mm: Math.max(0, Math.round((wallLength(wall) - width) / 2)),
      width_mm: width,
      height_mm: kind.height_mm,
      sill_height_mm: kind.sill_height_mm,
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

/**
 * The same opening, another kind. Its place and width stay; a French balcony goes to the
 * floor and a window taken off it comes back up to a sill.
 */
function rekind(opening: RoomOpening, kind: OpeningKind) {
  const toFloor = kind.sill_height_mm === 0 && (opening.sill_height_mm ?? 0) > 0
  const offFloor = kind.sill_height_mm > 0 && (opening.sill_height_mm ?? 0) === 0

  return patch(opening.id, {
    variant: kind.variant,
    label: describeKind({ type: opening.type, variant: kind.variant, width_mm: opening.width_mm, sill_height_mm: kind.sill_height_mm }),
    ...(toFloor || offFloor ? { sill_height_mm: kind.sill_height_mm, height_mm: kind.height_mm } : {}),
  })
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
    <div>
      <h3 class="font-medium">Kapılar ve pencereler</h3>
      <p class="mt-1 max-w-[60ch] text-sm leading-relaxed text-ink-secondary">
        Fotoğraftan okuduklarımı buraya koydum; yanlışsa planda tut, doğru duvara sürükle.
        Yenisini aşağıdan seç, sonra yerine taşı. Kapının önüne bir şey koymam.
      </p>
    </div>

    <!-- What can be added: the kinds a customer would name, one tap each. -->
    <div v-if="canEdit" class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2" role="toolbar" aria-label="Kapı ve pencere ekle">
      <div v-for="group in PALETTE" :key="group.type" class="flex flex-wrap items-center gap-1">
        <span class="text-xs text-muted">{{ group.label }}:</span>
        <button
          v-for="kind in group.kinds"
          :key="kind.variant"
          type="button"
          class="rounded-pill border border-line px-2.5 py-1 text-xs text-ink-secondary hover:bg-bg-muted disabled:opacity-40"
          :disabled="busy !== null"
          :aria-label="`${kind.label} ${group.label.toLocaleLowerCase('tr-TR')} ekle`"
          @click="add(kind)"
        >
          + {{ kind.label }}
        </button>
      </div>
    </div>

    <RcAlert v-if="notice" tone="danger" class="mt-4">{{ notice }}</RcAlert>

    <div class="mt-4 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
      <!-- The room from above; each door and window is a handle on its wall. -->
      <div>
        <!-- Height-bound, so a long room does not push the page past the window. -->
        <div class="h-[440px] overflow-hidden rounded-md border border-line bg-surface p-3">
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
          Henüz kapı ya da pencere yok. Yukarıdan türünü seç, sonra planda yerine sürükle.
        </li>

        <li
          v-for="opening in openings"
          :key="opening.id"
          class="rounded-md border border-line p-3"
          :class="{ 'opacity-60': busy === opening.id }"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-medium">{{ describeKind(opening) }}<span v-if="opening.width_mm"> · {{ cm(opening.width_mm) }} cm</span></p>
            <button v-if="canEdit" type="button" class="text-xs text-danger hover:underline" @click="remove(opening.id)">Kaldır</button>
          </div>

          <p class="mt-1 text-xs text-muted">
            {{ WALLS.find(wall => wall.value === opening.wall)?.label ?? 'Duvarı belli değil' }}
            <span v-if="opening.offset_mm !== null"> · köşeden {{ cm(opening.offset_mm) }} cm</span>
            — planda tutup taşı
          </p>

          <!-- The same opening as another kind: the reading said "window", the customer says "double". -->
          <div v-if="canEdit" class="mt-2 flex flex-wrap gap-1" role="group" :aria-label="`${describeKind(opening)} türü`">
            <button
              v-for="kind in kindsFor(opening.type as OpeningKind['type'])"
              :key="kind.variant"
              type="button"
              class="rounded-pill border px-2 py-0.5 text-[11px] transition-colors disabled:opacity-40"
              :class="variantOf(opening) === kind.variant ? 'border-charcoal bg-charcoal text-white' : 'border-line text-ink-secondary hover:bg-bg-muted'"
              :aria-pressed="variantOf(opening) === kind.variant"
              :disabled="busy !== null"
              @click="rekind(opening, kind)"
            >
              {{ kind.label }}
            </button>
          </div>

          <!-- Which jamb it hangs on and which way it opens: the quarter of floor a door needs. -->
          <div v-if="canEdit && hasSwing(opening)" class="mt-1.5 flex flex-wrap gap-1" role="group" :aria-label="`${describeKind(opening)} yönü`">
            <button
              v-if="variantOf(opening) !== 'double_door'"
              type="button"
              class="rounded-pill border border-line px-2 py-0.5 text-[11px] text-ink-secondary transition-colors hover:bg-bg-muted disabled:opacity-40"
              :disabled="busy !== null"
              title="Menteşeyi öbür tarafa al"
              @click="patch(opening.id, { swing: otherJamb(swingOf(opening)) })"
            >
              Menteşe {{ hingeIsLeft(opening.wall, swingOf(opening)) ? 'solda' : 'sağda' }} ⇄
            </button>
            <button
              type="button"
              class="rounded-pill border border-line px-2 py-0.5 text-[11px] text-ink-secondary transition-colors hover:bg-bg-muted disabled:opacity-40"
              :disabled="busy !== null"
              title="Öbür yöne açılsın"
              @click="patch(opening.id, { swing: otherWay(swingOf(opening)) })"
            >
              {{ opensIn(swingOf(opening)) ? 'İçeri açılır' : 'Dışarı açılır' }} ⇄
            </button>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>
