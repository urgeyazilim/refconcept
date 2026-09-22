<script setup lang="ts">
/**
 * Everything about one door or window, on the door or window.
 *
 * It used to be a list down the side of the page: five rows, most of them called "radiator ·
 * güney duvarı · ? cm'de, ? cm geniş", and changing the one you were pointing at meant
 * finding it among them by reading. The product owner said it plainly — the customer should
 * do this on the object, on the screen, without going anywhere.
 *
 * So the panel comes to the thing — in two steps, because the first attempt opened it full
 * size over the very door it was about, and a door with a panel on it cannot be dragged. A
 * press puts a small tag beside the opening, and the tag opens the panel. Pointing at
 * something and moving it stay one gesture; changing it is a second one, asked for.
 *
 * Both are anchored to the middle of the opening and follow it as the camera turns, both sit
 * beside it rather than over it, and both close on the floor or on Escape.
 *
 * Every control writes immediately. There is no Kaydet, because there is nothing here that
 * needs thinking about between typing a number and meaning it — and an unsaved panel that
 * closes when the camera moves would lose what was typed.
 */
import { ICONS } from '~/room3d/icons'
import {
  type DoorSwing,
  type OpeningKind,
  describeKind,
  hasJamb,
  hasSwing,
  hingeIsLeft,
  keepingJamb,
  kindsFor,
  otherJamb,
  swingOf,
  swingsFor,
  variantOf,
} from '~/room3d/openings'
import type { RoomOpening } from '~/room3d/types'

const props = defineProps<{
  opening: RoomOpening
  /** Where on screen the opening is, in canvas pixels. */
  x: number
  y: number
  /** How wide the canvas is, so a panel near the right edge opens to the left instead. */
  canvasWidth: number
}>()

const emit = defineEmits<{
  close: []
  remove: [id: string]
  rekind: [id: string, kind: OpeningKind]
  swing: [id: string, swing: DoorSwing]
  size: [id: string, field: 'width_mm' | 'height_mm' | 'sill_height_mm', centimetres: string]
}>()

const WALL_LABELS: Record<string, string> = { north: 'kuzey', east: 'doğu', south: 'güney', west: 'batı' }

/** Shut until asked for, and shut again whenever the panel moves to another opening. */
const open = ref(false)

watch(() => props.opening.id, () => {
  open.value = false
})

/**
 * Beside the opening, never over it.
 *
 * The first version centred the panel on the anchor, which is the middle of the door — so the
 * door was under the panel and could not be picked up. Everything is offset to the right by
 * more than half the opening's width on screen, and flips to the left when there is no room,
 * which is what a menu does at the edge of a window.
 *
 * The numbers are pixels of canvas, not millimetres of room: what matters is that a finger
 * can reach the door, and a finger is the same size however far away the wall is.
 */
const GAP_PX = 26

const flipped = computed(() => props.x > props.canvasWidth - 300)

const placement = computed(() => ({
  left: `${props.x + (flipped.value ? -GAP_PX : GAP_PX)}px`,
  top: `${props.y}px`,
  transform: flipped.value ? 'translate(-100%, -50%)' : 'translate(0, -50%)',
}))

/** Centimetres in the boxes, because that is what a tape measure reads. */
const cm = (millimetres: number | null): string => (millimetres === null ? '' : String(Math.round(millimetres / 10)))

const kinds = computed(() => kindsFor(props.opening.type as OpeningKind['type']))

const ways = computed(() => swingsFor(props.opening.type as OpeningKind['type']))

/**
 * Whether two swings mean the same direction, ignoring which jamb.
 *
 * The buttons offer directions; a swing carries a direction *and* a jamb, so `start_in` and
 * `end_in` are both "İçeri" and the jamb is a separate question.
 */
function sameWay(current: DoorSwing, wanted: DoorSwing): boolean {
  if (current === 'top_hung' || wanted === 'top_hung') {
    return current === wanted
  }

  return (current === 'start_in' || current === 'end_in') === (wanted === 'start_in' || wanted === 'end_in')
}

/**
 * "Kapalı" is a kind rather than a direction, and putting it among the directions is how a
 * customer would look for it: a window that does not open is the answer to "which way does
 * this open". The sealed pane is the only window with no answer.
 */
const sealed = computed(() => props.opening.type === 'window' && variantOf(props.opening) === 'fixed')

const sealedKind = computed(() => kinds.value.find(kind => kind.variant === 'fixed'))
</script>

<template>
  <!--
    Shut: a tag beside the opening saying what it is, with a way in.

    Small on purpose. The door has to stay reachable — pointing at something and moving it are
    one gesture, and a panel that opens under the finger that selected it takes the drag away.
  -->
  <button
    v-if="!open"
    type="button"
    class="pointer-events-auto absolute z-20 flex items-center gap-1.5 rounded-pill bg-surface/95 py-1 pr-2 pl-2.5 text-[11px] whitespace-nowrap text-ink-secondary shadow-md backdrop-blur-sm transition-colors hover:bg-surface"
    :style="placement"
    :aria-label="`${describeKind(opening)} ayarları`"
    @pointerdown.stop
    @click="open = true"
  >
    {{ describeKind(opening) }}
    <svg class="size-4 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 9 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 9a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z" />
    </svg>
  </button>

  <div
    v-else
    class="pointer-events-auto absolute z-20 w-64 rounded-md bg-surface/97 p-3 shadow-lg backdrop-blur-sm"
    :style="placement"
    role="dialog"
    :aria-label="`${describeKind(opening)} ayarları`"
    @pointerdown.stop
    @wheel.stop
  >
    <div class="flex items-start justify-between gap-2">
      <div>
        <p class="text-xs font-medium text-ink">{{ describeKind(opening) }}</p>
        <p class="text-[10px] text-muted">
          {{ opening.wall ? WALL_LABELS[opening.wall] : '—' }} duvarı ·
          {{ opening.offset_mm === null ? '?' : Math.round(opening.offset_mm / 10) }} cm'de
        </p>
      </div>

      <button
        type="button"
        class="-m-1 rounded-pill p-1 text-muted transition-colors hover:bg-bg-muted"
        aria-label="Kapat"
        @click="open = false"
      >
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
          <path :d="ICONS.close" />
        </svg>
      </button>
    </div>

    <!-- Which kind it is: the drawings, so it is chosen by looking rather than by reading. -->
    <div class="mt-2 grid grid-cols-3 gap-0.5" role="group" aria-label="Türü">
      <button
        v-for="kind in kinds"
        :key="kind.variant"
        type="button"
        class="flex flex-col items-center gap-0.5 rounded-sm px-1 py-1 text-center text-[9px] leading-tight transition-colors"
        :class="variantOf(opening) === kind.variant ? 'bg-charcoal text-white' : 'text-ink-secondary hover:bg-bg-muted'"
        :aria-pressed="variantOf(opening) === kind.variant"
        @click="emit('rekind', opening.id, kind)"
      >
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path :d="kind.icon" />
        </svg>
        {{ kind.label }}
      </button>
    </div>

    <!-- The measurements. Typed, because a tape measure gives a number and not a gesture. -->
    <div class="mt-2.5 space-y-1.5 text-[11px] text-muted">
      <label class="flex items-center gap-1.5">
        <span class="w-14">Genişlik</span>
        <input
          type="number"
          inputmode="numeric"
          class="w-16 rounded-sm border border-line bg-surface px-1.5 py-1 text-right text-ink tabular-nums"
          :value="cm(opening.width_mm)"
          @change="emit('size', opening.id, 'width_mm', ($event.target as HTMLInputElement).value)"
        >
        <span>cm</span>
      </label>
      <label class="flex items-center gap-1.5">
        <span class="w-14">Yükseklik</span>
        <input
          type="number"
          inputmode="numeric"
          class="w-16 rounded-sm border border-line bg-surface px-1.5 py-1 text-right text-ink tabular-nums"
          :value="cm(opening.height_mm)"
          @change="emit('size', opening.id, 'height_mm', ($event.target as HTMLInputElement).value)"
        >
        <span>cm</span>
      </label>
      <label v-if="opening.type === 'window'" class="flex items-center gap-1.5">
        <span class="w-14">Yerden</span>
        <input
          type="number"
          inputmode="numeric"
          class="w-16 rounded-sm border border-line bg-surface px-1.5 py-1 text-right text-ink tabular-nums"
          :value="cm(opening.sill_height_mm)"
          @change="emit('size', opening.id, 'sill_height_mm', ($event.target as HTMLInputElement).value)"
        >
        <span>cm</span>
      </label>
    </div>

    <!--
      Which way it opens.

      For a door this is the quarter of floor nothing may stand on. For a window it is whether
      it can be opened once the sofa is there — and "Kapalı" belongs here rather than among
      the kinds, because a window that does not open is what somebody is looking for when
      they ask which way this one does.
    -->
    <div v-if="hasSwing(opening) || sealed" class="mt-2.5" role="group" aria-label="Açılım yönü">
      <p class="text-[10px] tracking-wide text-muted uppercase">Açılım</p>

      <div class="mt-1 flex flex-wrap gap-1">
        <button
          v-for="way in ways"
          :key="way.swing"
          type="button"
          class="rounded-pill border px-2 py-0.5 text-[11px] transition-colors"
          :class="!sealed && sameWay(swingOf(opening), way.swing) ? 'border-charcoal bg-charcoal text-white' : 'border-line text-ink-secondary hover:bg-bg-muted'"
          :aria-pressed="!sealed && sameWay(swingOf(opening), way.swing)"
          @click="emit('swing', opening.id, keepingJamb(swingOf(opening), way.swing))"
        >
          {{ way.label.replace(' açılır', '') }}
        </button>

        <button
          v-if="sealedKind"
          type="button"
          class="rounded-pill border px-2 py-0.5 text-[11px] transition-colors"
          :class="sealed ? 'border-charcoal bg-charcoal text-white' : 'border-line text-ink-secondary hover:bg-bg-muted'"
          :aria-pressed="sealed"
          title="Açılmayan, duvarın parçası olan cam"
          @click="emit('rekind', opening.id, sealedKind)"
        >
          Kapalı
        </button>
      </div>

      <button
        v-if="!sealed && hasJamb(swingOf(opening)) && variantOf(opening) !== 'double_door'"
        type="button"
        class="mt-1 rounded-pill border border-line px-2 py-0.5 text-[11px] text-ink-secondary transition-colors hover:bg-bg-muted"
        title="Menteşeyi öbür tarafa al"
        @click="emit('swing', opening.id, otherJamb(swingOf(opening)))"
      >
        Menteşe {{ hingeIsLeft(opening.wall, swingOf(opening)) ? 'solda' : 'sağda' }} ⇄
      </button>
    </div>

    <button
      type="button"
      class="mt-3 w-full rounded-sm px-2 py-1 text-[11px] text-danger-strong transition-colors hover:bg-danger-subtle"
      @click="emit('remove', opening.id)"
    >
      Odadan kaldır
    </button>
  </div>
</template>
