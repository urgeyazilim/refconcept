<script setup lang="ts">
/**
 * The customer's room, in three dimensions, at the size they confirmed.
 *
 * Deliberately thin. Everything that knows about Three.js lives in `app/room3d`, and this
 * component's whole job is to own a canvas, hand it to a {@link RoomEditor} when it mounts,
 * and take it away again when it unmounts. A scene built inside a component's setup is a
 * scene rebuilt by every reactive change nobody expected to matter — the symptom is a room
 * that flickers and a laptop fan that will not stop.
 *
 * The editor pushes state out rather than the component reading it back. That is what keeps
 * the sidebar and the room from ever disagreeing about where the sofa is.
 *
 * The view controls are the ones from the product storyboard, in the same words: Üstten,
 * Perspektif, İçeriden. They are three different questions rather than three angles — a
 * perspective view says how a room *feels* and is useless for judging whether a walkway is
 * wide enough, because the sofa nearest the camera looks bigger than the wardrobe at the
 * back. The plan has no perspective, so two gaps that measure the same look the same.
 */
import { formatDistance } from '~/room3d/MeasurementEngine'
import { type EditorState, type OverlayLabel, RoomEditor } from '~/room3d/RoomEditor'
import type { DisplayMode, LayoutItem, RoomGeometry, RoomOpening, ViewMode } from '~/room3d/types'

const props = withDefaults(defineProps<{
  geometry: RoomGeometry
  openings: RoomOpening[]
  items?: LayoutItem[]
  /** Read-only shows the room and the furniture and lets nobody move anything. */
  editable?: boolean
}>(), {
  items: () => [],
  editable: false,
})

const emit = defineEmits<{ save: [items: LayoutItem[]] }>()

const canvas = ref<HTMLCanvasElement | null>(null)
const view = ref<ViewMode>('perspective')

/**
 * Whether the room is being looked at or measured.
 *
 * Two different questions, and the plan answers the second one properly: no perspective, so
 * two gaps that measure the same look the same, and labels that are text rather than pixels.
 */
const display = ref<DisplayMode>('3d')

/**
 * Shallow rather than a plain `let`: the template has to see it change, and shallow
 * because a Three.js scene wrapped in a deep proxy has every object in the graph made
 * reactive — thousands of getters on a hot path, for a graph nothing ever observes.
 */
const editor = shallowRef<RoomEditor | null>(null)

/**
 * `shallowRef`, because the editor hands over whole new state objects.
 *
 * Deep reactivity here would walk every item on every drag frame to find what changed, when
 * the answer is always "the object itself".
 */
const state = shallowRef<EditorState>({
  items: [],
  states: new Map(),
  selectedId: null,
  measurements: [],
  canUndo: false,
  canRedo: false,
  unsaved: false,
})

const labels = shallowRef<OverlayLabel[]>([])

const views: Array<{ value: ViewMode, label: string }> = [
  { value: 'top', label: 'Üstten' },
  { value: 'perspective', label: 'Perspektif' },
  { value: 'inside', label: 'İçeriden' },
]

const selected = computed(() =>
  state.value.items.find(item => item.id === state.value.selectedId) ?? null,
)

const problems = computed(() =>
  state.value.items.filter(item => state.value.states.get(item.id) !== 'ok'),
)

/** How far an arrow key moves something: a centimetre, or ten with shift held. */
const NUDGE_MM = 10
const NUDGE_COARSE_MM = 100

function onKeydown(event: KeyboardEvent): void {
  if (editor.value === null || !props.editable) {
    return
  }

  // Undo works with nothing selected; everything else needs something to act on.
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
    event.preventDefault()
    if (event.shiftKey) {
      editor.value.redo()
    }
    else {
      editor.value.undo()
    }

    return
  }

  const id = state.value.selectedId

  if (id === null) {
    return
  }

  const step = event.shiftKey ? NUDGE_COARSE_MM : NUDGE_MM

  switch (event.key) {
    case 'ArrowLeft':
      editor.value.nudge(id, -step, 0)
      break
    case 'ArrowRight':
      editor.value.nudge(id, step, 0)
      break
    case 'ArrowUp':
      editor.value.nudge(id, 0, -step)
      break
    case 'ArrowDown':
      editor.value.nudge(id, 0, step)
      break
    case 'r':
    case 'R':
      editor.value.rotate(id, 90)
      break
    default:
      return
  }

  // Only for the keys that did something: swallowing the rest would break typing in any
  // field that happens to be open beside the scene.
  event.preventDefault()
}

onMounted(() => {
  if (canvas.value === null) {
    return
  }

  editor.value = new RoomEditor(canvas.value, props.geometry, props.openings, {
    onChange: (next) => {
      state.value = next
    },
    onOverlay: (next) => {
      labels.value = next
    },
    // Read-only scenes pass no persist callback at all, so there is no path by which one can
    // write a layout — rather than a flag somewhere that has to stay false.
    onPersist: props.editable ? items => emit('save', items) : undefined,
  })

  editor.value.setItems(props.items)

  window.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)

  editor.value?.dispose()
  editor.value = null
})

watch(view, mode => editor.value?.setView(mode))

// A corrected measurement is a different room, not a moved camera.
watch(
  () => [props.geometry, props.openings] as const,
  () => editor.value?.setRoom(props.geometry, props.openings),
  { deep: true },
)

// Only when the caller replaces the list — the editor owns positions once it has them, and
// writing them back in from the parent mid-drag would fight the drag.
watch(
  () => props.items,
  items => editor.value?.setItems(items),
)

/** The canvas as a PNG, for the render pipeline. */
defineExpose({ snapshot: () => editor.value?.snapshot() ?? null })
</script>

<template>
  <div class="space-y-3">
    <div class="relative aspect-[4/3] w-full overflow-hidden rounded-md bg-bg-muted">
      <!--
        `block` because a canvas is inline by default, which leaves a few pixels of line-height
        underneath it and a scrollbar that appears at some window sizes and not others.

        `v-show` rather than `v-if`: removing the canvas destroys its WebGL context, and
        getting one back costs a visible pause and, after a few switches, a browser that
        refuses — there is a hard limit on live contexts per page.
      -->
      <canvas v-show="display === '3d'" ref="canvas" class="block size-full touch-none" />

      <RoomPlanSvg
        v-if="display === 'plan'"
        :geometry="geometry"
        :openings="openings"
        :items="state.items"
        :states="state.states"
        :selected-id="state.selectedId"
        @select="editor?.select($event)"
      />

      <!--
        Measurements as HTML over the canvas rather than text drawn into it. Text in WebGL is
        either a texture that blurs the moment somebody zooms or a font atlas nobody wants to
        maintain for the sake of "185 cm".
      -->
      <div v-if="display === '3d'" class="pointer-events-none absolute inset-0">
        <span
          v-for="label in labels"
          :key="label.id"
          class="absolute -translate-x-1/2 -translate-y-1/2 rounded-pill bg-charcoal/85 px-2 py-0.5 text-[11px] whitespace-nowrap text-white tabular-nums"
          :style="{ left: `${label.x}px`, top: `${label.y}px` }"
        >{{ label.text }}</span>
      </div>

      <div class="absolute top-4 right-4 flex gap-1 rounded-pill bg-surface/90 p-1 backdrop-blur-sm">
        <button
          type="button"
          class="rounded-pill px-3 py-1.5 text-xs transition-colors"
          :class="display === 'plan' ? 'bg-charcoal text-white' : 'text-ink-secondary hover:bg-bg-muted'"
          @click="display = display === 'plan' ? '3d' : 'plan'"
        >
          Plan
        </button>

        <span class="my-1 w-px bg-line" />

        <button
          v-for="option in views"
          :key="option.value"
          :disabled="display === 'plan'"
          type="button"
          class="rounded-pill px-3 py-1.5 text-xs transition-colors disabled:opacity-40"
          :class="view === option.value ? 'bg-charcoal text-white' : 'text-ink-secondary hover:bg-bg-muted'"
          @click="view = option.value"
        >
          {{ option.label }}
        </button>
      </div>

      <div v-if="editable" class="absolute top-4 left-4 flex gap-1 rounded-pill bg-surface/90 p-1 backdrop-blur-sm">
        <button
          type="button"
          class="rounded-pill px-3 py-1.5 text-xs text-ink-secondary transition-colors hover:bg-bg-muted disabled:opacity-40"
          :disabled="!state.canUndo"
          @click="editor?.undo()"
        >
          Geri al
        </button>
        <button
          type="button"
          class="rounded-pill px-3 py-1.5 text-xs text-ink-secondary transition-colors hover:bg-bg-muted disabled:opacity-40"
          :disabled="!state.canRedo"
          @click="editor?.redo()"
        >
          İleri al
        </button>
      </div>

      <p class="absolute right-4 bottom-4 left-4 text-right text-xs text-muted">
        {{ (geometry.width_mm / 1000).toFixed(2) }} × {{ (geometry.length_mm / 1000).toFixed(2) }} m ·
        tavan {{ (geometry.height_mm / 1000).toFixed(2) }} m
      </p>
    </div>

    <div v-if="editable" class="grid gap-3 md:grid-cols-2">
      <!-- What is selected, and everything that can be done to it. -->
      <div class="rounded-md border border-line bg-surface p-4">
        <template v-if="selected === null">
          <p class="text-sm text-muted">
            Taşımak istediğiniz ürüne tıklayın. Yön tuşlarıyla santimetre santimetre
            kaydırabilir, R ile çevirebilirsiniz.
          </p>
        </template>

        <template v-else>
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-sm font-medium text-ink">
                {{ selected.name }}
              </p>
              <p class="text-xs text-muted tabular-nums">
                {{ selected.width_mm === null || selected.depth_mm === null
                  ? 'Ölçüsü girilmemiş'
                  : `${formatDistance(selected.width_mm)} × ${formatDistance(selected.depth_mm)}` }}
                · {{ selected.rotation_y_deg }}°
              </p>
            </div>

            <span
              class="rounded-pill px-2 py-0.5 text-[11px]"
              :class="{
                'bg-bg-muted text-ink-secondary': state.states.get(selected.id) === 'ok',
                'bg-warning-subtle text-warning-strong': state.states.get(selected.id) === 'warning',
                'bg-danger-subtle text-danger-strong': state.states.get(selected.id) === 'blocked',
              }"
            >
              {{ state.states.get(selected.id) === 'blocked'
                ? 'Buraya sığmıyor'
                : state.states.get(selected.id) === 'warning' ? 'Pencerenin önünde' : 'Uygun' }}
            </span>
          </div>

          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="rounded-pill border border-line px-3 py-1.5 text-xs hover:bg-bg-muted" @click="editor?.rotate(selected.id, -90)">
              ⟲ 90°
            </button>
            <button type="button" class="rounded-pill border border-line px-3 py-1.5 text-xs hover:bg-bg-muted" @click="editor?.rotate(selected.id, 90)">
              ⟳ 90°
            </button>
            <button
              type="button"
              class="rounded-pill border border-line px-3 py-1.5 text-xs hover:bg-bg-muted"
              @click="editor?.toggleLock(selected.id)"
            >
              {{ selected.locked ? 'Kilidi aç' : 'Yerini kilitle' }}
            </button>
            <button type="button" class="rounded-pill border border-line px-3 py-1.5 text-xs text-danger-strong hover:bg-danger-subtle" @click="editor?.remove(selected.id)">
              Kaldır
            </button>
          </div>

          <!--
            The gaps around the selected piece, written out. 60 cm is a person sideways and
            90 cm is a person carrying something; neither is visible on a screen at any zoom.
          -->
          <dl v-if="state.measurements.length > 0" class="mt-3 space-y-1 border-t border-line pt-3 text-xs">
            <div v-for="measurement in state.measurements" :key="`${measurement.towards}-${measurement.mm}`" class="flex justify-between gap-3">
              <dt class="text-muted">
                {{ measurement.towards }} arası
              </dt>
              <dd class="tabular-nums text-ink">
                {{ formatDistance(measurement.mm) }}
              </dd>
            </div>
          </dl>
        </template>
      </div>

      <!-- Everything in the room, and anything wrong with it. -->
      <div class="rounded-md border border-line bg-surface p-4">
        <div class="flex items-center justify-between">
          <p class="text-sm font-medium text-ink">
            Odadaki ürünler ({{ state.items.length }})
          </p>
          <span v-if="state.unsaved" class="text-xs text-muted">Kaydediliyor…</span>
        </div>

        <ul class="mt-2 max-h-48 space-y-1 overflow-y-auto">
          <li v-for="item in state.items" :key="item.id">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-2 rounded-sm px-2 py-1.5 text-left text-xs transition-colors"
              :class="item.id === state.selectedId ? 'bg-bg-muted' : 'hover:bg-bg-muted'"
              @click="editor?.select(item.id)"
            >
              <span class="truncate text-ink">{{ item.name }}</span>
              <span class="shrink-0 text-muted">
                <template v-if="item.locked">🔒</template>
                <template v-if="state.states.get(item.id) === 'blocked'">⛔</template>
                <template v-else-if="state.states.get(item.id) === 'warning'">⚠</template>
              </span>
            </button>
          </li>
        </ul>

        <p v-if="problems.length > 0" class="mt-3 border-t border-line pt-3 text-xs text-muted">
          {{ problems.length }} ürün için uyarı var. Kırmızı olanlar bu odaya bu şekilde
          yerleşmiyor; sipariş vermeden önce düzeltilmesi gerekir.
        </p>
      </div>
    </div>
  </div>
</template>
