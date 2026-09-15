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

/**
 * `save` is the debounced write; `change` is every change, immediately.
 *
 * The page needs the second because it asks the server where a new product belongs, and
 * that answer depends on what is already in the room *now* — not on what was last written
 * a second and a half ago. Sending the stale list puts every new piece in the same place.
 */
const emit = defineEmits<{ save: [items: LayoutItem[]], change: [items: LayoutItem[]] }>()

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
 * Whether the distances are drawn over the scene.
 *
 * On by default, because the measurements are the reason a plan beats a photograph. Off is
 * for the moment somebody wants to look at the room rather than at the numbers — and for a
 * screenshot, where four labels over a sofa are four labels in the picture.
 */
const showMeasurements = ref(true)

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
      // The turn handle, not a quarter turn: the buttons do quarter turns.
      setTool(tool.value === 'rotate' ? 'translate' : 'rotate')
      break
    case 'Shift':
      // Held: the turn handle stops snapping to fifteen degrees.
      editor.value.setFreeRotation(true)
      return
    default:
      return
  }

  // Only for the keys that did something: swallowing the rest would break typing in any
  // field that happens to be open beside the scene.
  event.preventDefault()
}

function onKeyup(event: KeyboardEvent): void {
  if (event.key === 'Shift') {
    editor.value?.setFreeRotation(false)
  }
}

/** Which handles the selected piece shows: arrows to move it, a ring to turn it. */
const tool = ref<'translate' | 'rotate'>('translate')

function setTool(mode: 'translate' | 'rotate'): void {
  tool.value = mode
  editor.value?.setTool(mode)
}

onMounted(() => {
  if (canvas.value === null) {
    return
  }

  editor.value = new RoomEditor(canvas.value, props.geometry, props.openings, {
    onChange: (next) => {
      state.value = next
      emit('change', next.items)
    },
    onOverlay: (next) => {
      labels.value = next
    },
    // Read-only scenes pass no persist callback at all, so there is no path by which one can
    // write a layout — rather than a flag somewhere that has to stay false.
    onPersist: props.editable ? items => emit('save', items) : undefined,
  })

  editor.value.setItems(props.items)

  // A handle for the browser tests and for poking at the scene from the console. Dev only:
  // nothing in production should reach the editor except through this component.
  if (import.meta.dev) {
    (window as unknown as { __rcEditor?: RoomEditor }).__rcEditor = editor.value
  }

  window.addEventListener('keydown', onKeydown)
  window.addEventListener('keyup', onKeyup)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('keyup', onKeyup)

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

/**
 * What the page around this component may do to the room.
 *
 * A snapshot for the render pipeline, and a way to put a product in — the catalogue search
 * lives on the page, because what is worth offering depends on the screen, and the scene
 * should not know what a shop is.
 */
defineExpose({
  snapshot: () => editor.value?.snapshot() ?? null,
  add: (item: LayoutItem) => editor.value?.add(item),
})
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
      <div v-if="display === '3d' && showMeasurements" class="pointer-events-none absolute inset-0">
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

        <!--
          Off is for looking at the room rather than at the numbers — and for a screenshot,
          where four labels over a sofa are four labels in the picture.
        -->
        <button
          type="button"
          class="rounded-pill px-3 py-1.5 text-xs transition-colors"
          :class="showMeasurements ? 'bg-charcoal text-white' : 'text-ink-secondary hover:bg-bg-muted'"
          @click="showMeasurements = !showMeasurements"
        >
          Ölçüler
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

              <!--
                Said plainly when the shape is a guess.

                A mesh made from a single photograph never saw the back of the sofa, so the
                back it shows is invented. Somebody judging a purchase from the far side of
                the room deserves to know which half of what they are looking at was
                photographed and which half was inferred.
              -->
              <p v-if="selected.model_url === null" class="mt-1 text-xs text-muted">
                Bu ürünün 3B modeli henüz yok; gerçek ölçülerinde yaklaşık şekliyle gösteriliyor.
              </p>
              <p v-else-if="selected.model_source === 'ai'" class="mt-1 text-xs text-muted">
                3B görünüm fotoğraftan üretilmiş temsilî bir modeldir; arka yüzü tahminîdir.
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

          <!--
            The handles on the piece itself: arrows to slide it along the floor, a ring to
            turn it. The storyboard's panel 5, and what "professional 3D" meant when the first
            editor was found not to be it. R switches; Shift frees the turn from 15° steps.
          -->
          <div class="mt-3 inline-flex rounded-pill border border-line p-0.5 text-xs">
            <button
              type="button"
              class="rounded-pill px-3 py-1"
              :class="tool === 'translate' ? 'bg-charcoal text-white' : 'hover:bg-bg-muted'"
              @click="setTool('translate')"
            >
              Taşı
            </button>
            <button
              type="button"
              class="rounded-pill px-3 py-1"
              :class="tool === 'rotate' ? 'bg-charcoal text-white' : 'hover:bg-bg-muted'"
              @click="setTool('rotate')"
            >
              Döndür
            </button>
          </div>

          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="rounded-pill border border-line px-3 py-1.5 text-xs hover:bg-bg-muted" @click="editor?.rotate(selected.id, -90)">
              ⟲ 90°
            </button>
            <button type="button" class="rounded-pill border border-line px-3 py-1.5 text-xs hover:bg-bg-muted" @click="editor?.rotate(selected.id, 90)">
              ⟳ 90°
            </button>
            <!--
              The tidy-ups a pointer is worst at.

              "Against the wall" is a position no drag ever quite reaches, and a sideboard
              30 mm off the wall looks like a mistake in every render made from the layout
              afterwards. Aligning also turns the piece to face the room, because a sofa
              against a wall with its back to the middle of it is not what anybody meant.
            -->
            <button type="button" class="rounded-pill border border-line px-3 py-1.5 text-xs hover:bg-bg-muted" @click="editor?.alignToWall(selected.id)">
              Duvara hizala
            </button>
            <button type="button" class="rounded-pill border border-line px-3 py-1.5 text-xs hover:bg-bg-muted" @click="editor?.centreInRoom(selected.id)">
              Oda merkezine
            </button>
            <!-- A pair of bedside tables, four dining chairs: the search has been done once. -->
            <button type="button" class="rounded-pill border border-line px-3 py-1.5 text-xs hover:bg-bg-muted" @click="editor?.duplicate(selected.id)">
              Kopyala
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
            How high it hangs.

            A picture, a mirror, a wall shelf, a television: anything above the floor is out
            of the way of everything on it, which is what turns a box standing in the middle
            of the room into something on a wall. Centimetres, like everything else somebody
            measures with a tape.
          -->
          <label class="mt-3 flex items-center gap-2 text-xs text-muted">
            Yerden yükseklik (cm)
            <input
              type="number"
              min="0"
              max="290"
              step="5"
              class="w-24 rounded-sm border border-line bg-surface px-2 py-1 text-xs tabular-nums"
              :value="Math.round(selected.position_y_mm / 10)"
              @change="editor?.setHeight(selected.id, Number(($event.target as HTMLInputElement).value) * 10)"
            >
          </label>

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
