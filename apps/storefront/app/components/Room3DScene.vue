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
import { ICONS, type IconName } from '~/room3d/icons'
import { OPENING_TYPES, type OpeningKind, type OpeningType, TYPE_LABELS, kindsFor } from '~/room3d/openings'
import type { DisplayMode, LayoutItem, RoomGeometry, RoomOpening, ViewMode, WallName } from '~/room3d/types'

const props = withDefaults(defineProps<{
  geometry: RoomGeometry
  openings: RoomOpening[]
  items?: LayoutItem[]
  /** Read-only shows the room and the furniture and lets nobody move anything. */
  editable?: boolean
  /**
   * Fill the height given by the parent, with the panels in one scrolling column beside the
   * room rather than under it. For a page that is a workspace; the lab and the design page
   * keep the room above its panels.
   */
  workspace?: boolean
  /**
   * Only the doors and windows. The room step confirms the shell, so the furniture
   * inspector and the product list beside it are somebody else's step and are hidden.
   */
  openingsOnly?: boolean
  /**
   * Whether the size drawn is the customer's or the fallback box.
   *
   * The scene has to draw something before anybody has measured anything, so it falls back
   * to a room-shaped default. Saying so keeps the corner from printing that default as if
   * it were a measurement.
   */
  measured?: boolean
  /**
   * A link to the room measured from its photographs, when one has been made.
   *
   * Shown instead of the room we drew, on request. The drawn room is a guess at a shape; this
   * is the shape, and putting them behind one button is how somebody can tell.
   */
  scanUrl?: string | null
  /**
   * Which of the four drawn walls actually faces north.
   *
   * The reading names the walls from photographs and gets it wrong often enough to be worth
   * a control: a window facing east recorded as west makes every sentence about light
   * backwards. Nothing in the room moves when this changes — the four labels do.
   */
  northWall?: WallName
}>(), {
  items: () => [],
  editable: false,
  workspace: false,
  openingsOnly: false,
  measured: true,
  scanUrl: null,
  northWall: 'north',
})

/**
 * `save` is the debounced write; `change` is every change, immediately.
 *
 * The page needs the second because it asks the server where a new product belongs, and
 * that answer depends on what is already in the room *now* — not on what was last written
 * a second and a half ago. Sending the stale list puts every new piece in the same place.
 */
const emit = defineEmits<{
  save: [items: LayoutItem[]]
  change: [items: LayoutItem[]]
  /** A door or window was dragged and let go on a wall — on the plan or in the room. */
  moveOpening: [id: string, offsetMm: number, wall: WallName]
  /** A door or window was picked from the palette: put one in the room to be dragged. */
  addOpening: [kind: OpeningKind]
  /** A door or window's end was dragged on the plan: this wide now, starting here. */
  resizeOpening: [id: string, offsetMm: number, widthMm: number]
  /** The customer said which of the drawn walls faces north. */
  turnCompass: [wall: WallName]
  /** The room's own measurements, typed into the corner of the scene. */
  resizeRoom: [widthMm: number, lengthMm: number, heightMm: number]
}>()

/**
 * Whether the door and window palette is open.
 *
 * Open by default on the room step, where putting openings in is the job; folded away
 * everywhere else, because on the design screen it is a column of eleven buttons standing
 * over the room somebody is trying to look at. It remembers nothing between visits on
 * purpose — a palette that is closed on arrival for reasons from last week is a palette
 * nobody finds.
 */
const paletteOpen = ref(props.openingsOnly)

/** The room's measurements, open for typing over the corner of the scene. */
const sizing = ref(false)

/** Whether the compass is open for correcting. */
const turningCompass = ref(false)

/**
 * The four walls as the scene draws them, named as the scene draws them.
 *
 * Deliberately the drawn names rather than the corrected ones: the question is "which of the
 * walls you are looking at faces north", and answering it with labels that have already been
 * corrected is a loop nobody can reason about.
 */
const WALL_CHOICES: Array<{ value: WallName, label: string }> = [
  { value: 'north', label: 'Üstteki' },
  { value: 'east', label: 'Sağdaki' },
  { value: 'south', label: 'Alttaki' },
  { value: 'west', label: 'Soldaki' },
]

/**
 * The shortcut sheet, over the room.
 *
 * Every tool of this kind has one and it is the difference between a customer who drags
 * things about and a customer who is quick: nobody discovers that Shift frees the rotation
 * snap, or that space gives the gesture to the camera, by trying things. The tooltips teach
 * one key at a time; this is the whole list, on the key the whole world uses for it.
 */
const helping = ref(false)

/**
 * What was just taken out of the room, and for how long the offer to put it back stands.
 *
 * Not a confirmation dialogue. "Bunu silmek istediğinize emin misiniz?" is asked of every
 * delete and read on none of them — it trains the hand to press Evet before the eye has
 * finished reading, which is how the wrong thing gets deleted with permission. An undo
 * offered afterwards costs nothing when the delete was meant, and is there when it was not.
 *
 * Ctrl+Z does the same thing and always has; this is for the customer who does not know that,
 * which on this screen is most of them.
 */
const removed = ref<string | null>(null)

let removedTimer: ReturnType<typeof setTimeout> | null = null

function remove(id: string, name: string): void {
  editor.value?.remove(id)
  removed.value = name

  if (removedTimer !== null) {
    clearTimeout(removedTimer)
  }

  // Long enough to notice and reach, short enough not to sit over the room.
  removedTimer = setTimeout(() => {
    removed.value = null
  }, 7_000)
}

function putBack(): void {
  editor.value?.undo()
  removed.value = null

  if (removedTimer !== null) {
    clearTimeout(removedTimer)
    removedTimer = null
  }
}

/**
 * What the hand and the keyboard do, in the order somebody learns them.
 *
 * Grouped by what the person is trying to do rather than by which key it is: "I want to move
 * something", "I want to look somewhere else". A list sorted by keycap is a reference for
 * somebody who already knows.
 */
const HELP: Array<{ title: string, rows: Array<[string, string]> }> = [
  {
    title: 'Eşyalar',
    rows: [
      ['Tut ve sürükle', 'Taşır. Duvara ve diğer eşyaya kendi yaslanır'],
      ['Halkayı çevir', 'Döndürür — 15° adımlarla'],
      ['Shift + halka', 'İstediğin açıya serbest çevirir'],
      ['Ok tuşları', '1 cm oynatır'],
      ['Shift + ok', '10 cm oynatır'],
      ['R', '90° sağa çevirir'],
      ['Ctrl + D', 'Bir tane daha koyar, yanına'],
      ['Delete', 'Odadan çıkarır'],
      ['Esc', 'Elindekini bırakır, sonra seçimi bırakır'],
    ],
  },
  {
    title: 'Bakış',
    rows: [
      ['Boş yerde sürükle', 'Sahneyi çevirir'],
      ['Boşluk + sürükle', 'Eşyanın üstünde olsan bile sahneyi çevirir'],
      ['Tekerlek', 'İmlecin olduğu yere yakınlaşır'],
      ['+ / −', 'Yakınlaşır, uzaklaşır'],
      ['1 · 2 · 3', 'Tepeden · dışarıdan · içeride'],
      ['Çift tık', 'Tıkladığın eşyaya yakınlaşır'],
      ['W A S D', 'İçerideyken odada yürür'],
    ],
  },
  {
    title: 'Oda',
    rows: [
      ['Soldaki paletten seç', 'Kapı ya da pencere koyar'],
      ['Kapıyı tut ve sürükle', 'Duvar boyunca kaydırır; başka duvara da geçer'],
      ['Sağ alttaki ölçüye bas', 'Odanın boyunu buradan değiştirir'],
      ['Ctrl + Z', 'Geri alır'],
      ['Ctrl + Shift + Z', 'İleri alır'],
    ],
  },
]

const size = reactive({ width: '', length: '', height: '' })

/** Centimetres in the boxes, because that is how a room is measured with a tape. */
function openSizing(): void {
  size.width = String(Math.round(props.geometry.width_mm / 10))
  size.length = String(Math.round(props.geometry.length_mm / 10))
  size.height = String(Math.round(props.geometry.height_mm / 10))
  sizing.value = true
}

/**
 * A room somebody could actually be standing in.
 *
 * One metre to twenty, and a ceiling between two and five. Not politeness: the scene frames
 * the camera from these, and a typo of 300 for 3.00 puts the customer inside a three
 * hundred metre hall with their sofa a speck on the floor, from which there is no obvious
 * way back.
 */
const sizeIsSane = computed(() => {
  const w = Number(size.width)
  const l = Number(size.length)
  const h = Number(size.height)

  return [w, l].every(value => Number.isFinite(value) && value >= 100 && value <= 2_000)
    && Number.isFinite(h) && h >= 200 && h <= 500
})

function applySize(): void {
  if (!sizeIsSane.value) {
    return
  }

  emit('resizeRoom', Math.round(Number(size.width) * 10), Math.round(Number(size.length) * 10), Math.round(Number(size.height) * 10))
  sizing.value = false
}

/** The palette: what a customer can put on a wall, grouped as they think of them. */
const PALETTE: Array<{ type: OpeningType, label: string, kinds: OpeningKind[] }> = OPENING_TYPES.map(type => ({
  type,
  label: TYPE_LABELS[type],
  kinds: kindsFor(type),
}))

const canvas = ref<HTMLCanvasElement | null>(null)
const view = ref<ViewMode>('perspective')

/**
 * Whether the room is being looked at or measured.
 *
 * Two different questions, and the plan answers the second one properly: no perspective, so
 * two gaps that measure the same look the same, and labels that are text rather than pixels.
 */
const display = ref<DisplayMode>('3d')

/** Whether the reconstruction is on screen instead of the room we drew. */
const showingScan = ref(false)
const scanFailed = ref(false)

async function toggleScan() {
  if (props.scanUrl === null || props.scanUrl === undefined) {
    return
  }

  if (showingScan.value) {
    editor.value?.hideScan()
    showingScan.value = false

    return
  }

  scanFailed.value = false

  try {
    await editor.value?.showScan(props.scanUrl)
    showingScan.value = true
  }
  catch {
    scanFailed.value = true
  }
}

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

/**
 * The three ways of looking at the room, each with a drawing and a number key.
 *
 * The hints are not decoration. "Perspektif" and "İçeriden" are both views from inside a
 * house to anybody who has not used a planner, and which one shows what is exactly the thing
 * a first-time customer cannot guess.
 */
const views: Array<{ value: ViewMode, label: string, icon: IconName, keys: string, hint: string }> = [
  { value: 'top', label: 'Tepeden', icon: 'top', keys: '1', hint: 'Yerleşimi düzenlemek için en kolayı' },
  { value: 'perspective', label: 'Dışarıdan', icon: 'perspective', keys: '2', hint: 'Odaya yukarıdan bakış' },
  { value: 'inside', label: 'İçeride', icon: 'inside', keys: '3', hint: 'Odanın içinde dur; W A S D ile yürü' },
]

const selected = computed(() =>
  state.value.items.find(item => item.id === state.value.selectedId) ?? null,
)

const problems = computed(() =>
  state.value.items.filter(item => state.value.states.get(item.id) !== 'ok'),
)

/**
 * What the room costs as it stands (K20): every piece that has a price, summed, in the
 * currency of the first one. A piece without a price is counted as none and said so.
 */
const total = computed(() => {
  const priced = state.value.items.filter(item => item.price !== null && item.price !== undefined)
  const currency = priced[0]?.price?.currency ?? 'TRY'
  const minor = priced.reduce((sum, item) => sum + (item.price?.amount_minor ?? 0), 0)

  return {
    minor,
    currency,
    formatted: new Intl.NumberFormat('tr-TR', { style: 'currency', currency, maximumFractionDigits: 0 }).format(minor / 100),
    unpriced: state.value.items.length - priced.length,
  }
})

/** How far an arrow key moves something: a centimetre, or ten with shift held. */
const NUDGE_MM = 10
const NUDGE_COARSE_MM = 100

function onKeydown(event: KeyboardEvent): void {
  if (editor.value === null || !props.editable) {
    return
  }

  // Keys typed into a field beside the scene are the field's: a Backspace in the product
  // search must not delete the sofa.
  const target = event.target as HTMLElement | null

  if (target !== null && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable)) {
    return
  }

  /*
   * Space: the view takes the gesture, whatever it lands on.
   *
   * Held rather than pressed, like every other 3D tool. Without it a piece of furniture is a
   * hole in the camera — press anywhere on the sofa and the view will not turn — and in a
   * room whose whole point is a large sofa in the middle of it, that is most of the screen.
   * The default has to go too, or the page scrolls underneath.
   */
  if (event.code === 'Space') {
    event.preventDefault()
    editor.value.wantCamera(true)

    return
  }

  /*
   * Escape: put down whatever is being carried, where it was picked up; then, on a second
   * press, let go of the selection. In that order — a half-finished drag is the thing the
   * hand is in the middle of, and the selection is not going anywhere.
   */
  if (event.key === 'Escape') {
    if (helping.value) {
      helping.value = false

      return
    }

    editor.value.cancelGesture()
    editor.value.select(null)

    return
  }

  /*
   * The three views on 1, 2, 3 and the shortcut sheet on ?.
   *
   * Numbers because that is where every 3D tool puts them, and because a customer who has
   * found one has found all three. They belong to the view, so nothing needs selecting.
   */
  if (event.key === '1' || event.key === '2' || event.key === '3') {
    event.preventDefault()
    // Indexed by the key itself rather than by arithmetic, so the type says what the
    // three are and a fourth view cannot quietly land nowhere.
    view.value = ({ 1: 'top', 2: 'perspective', 3: 'inside' } as const)[Number(event.key) as 1 | 2 | 3]

    return
  }

  if (event.key === '?' || (event.shiftKey && event.key === '/')) {
    event.preventDefault()
    helping.value = !helping.value

    return
  }

  // Zoom belongs to the view, so it works with nothing selected — as does undo, below.
  if (event.key === '+' || event.key === '=') {
    event.preventDefault()
    editor.value.zoom('in')

    return
  }

  if (event.key === '-' || event.key === '_') {
    event.preventDefault()
    editor.value.zoom('out')

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

  // The piece's own shortcuts: delete it, copy it, let go of it.
  if (event.key === 'Delete' || event.key === 'Backspace') {
    event.preventDefault()
    remove(id, selected.value?.name ?? 'Eşya')

    return
  }

  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'd') {
    event.preventDefault()
    editor.value.duplicate(id)

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

  if (event.code === 'Space') {
    editor.value?.wantCamera(false)
  }
}

/**
 * The window lost focus with a key held down.
 *
 * Alt-tabbing away while holding space and coming back leaves the editor believing the space
 * bar is still down: every press then turns the camera and nothing can be picked up, with no
 * way to find out why short of pressing and releasing space again.
 */
function onBlur(): void {
  editor.value?.wantCamera(false)
  editor.value?.setFreeRotation(false)
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
    onMoveOpening: props.editable ? (id, offsetMm, wall) => emit('moveOpening', id, offsetMm, wall) : undefined,
  })

  editor.value.setItems(props.items)

  // A handle for the browser tests and for poking at the scene from the console. Dev only:
  // nothing in production should reach the editor except through this component.
  if (import.meta.dev) {
    (window as unknown as { __rcEditor?: RoomEditor }).__rcEditor = editor.value
  }

  window.addEventListener('keydown', onKeydown)
  window.addEventListener('keyup', onKeyup)
  window.addEventListener('blur', onBlur)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('keyup', onKeyup)
  window.removeEventListener('blur', onBlur)

  // A timer that fires into a component that has gone writes to a ref nobody is watching.
  if (removedTimer !== null) {
    clearTimeout(removedTimer)
    removedTimer = null
  }

  editor.value?.dispose()
  editor.value = null
})

watch(view, mode => editor.value?.setView(mode))

watch(() => props.northWall, wall => editor.value?.setNorthWall(wall))

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
  // The room as a structure rather than as a picture: one view from inside, as a depth map
  // and as colour. A renderer handed these cannot move a wall, because the geometry stops
  // being a suggestion and becomes an input.
  structureSnapshot: () => editor.value?.structureSnapshot() ?? null,
  add: (item: LayoutItem) => editor.value?.add(item),
})
</script>

<template>
  <div :class="workspace ? 'grid gap-3 lg:grid-cols-[minmax(0,1fr)_320px] lg:grid-rows-[minmax(0,1fr)]' : 'space-y-3'">
    <!-- Workspace: the page gives the height; one explicit row of that height, or the taller column would stretch the row and the room with it. -->
    <div class="relative overflow-hidden rounded-md bg-bg-muted" :class="workspace ? 'h-full min-h-0' : 'aspect-[4/3] w-full'">
      <!--
        `block` because a canvas is inline by default, which leaves a few pixels of line-height
        underneath it and a scrollbar that appears at some window sizes and not others.

        `v-show` rather than `v-if`: removing the canvas destroys its WebGL context, and
        getting one back costs a visible pause and, after a few switches, a browser that
        refuses — there is a hard limit on live contexts per page.
      -->
      <!-- A double-click flies in to the selected piece, or back out to the whole room. -->
      <canvas v-show="display === '3d'" ref="canvas" class="block size-full touch-none" @dblclick="editor?.focusSelected()" />

      <!-- Inside the room the camera is walked, not orbited, and that has to be said once. -->
      <p
        v-if="display === '3d' && view === 'inside'"
        class="pointer-events-none absolute bottom-3 left-3 rounded-pill bg-charcoal/80 px-3 py-1 text-[11px] text-white"
      >
        Sürükleyerek etrafa bakın · W A S D ile yürüyün
      </p>

      <!--
        The guide's one line in the room (REHBER.md): what to do with the hand, said once,
        gone the moment something is picked up. An empty room says how to fill it.
      -->
      <p
        v-else-if="display === '3d' && editable && selected === null"
        class="pointer-events-none absolute bottom-3 left-1/2 max-w-[46ch] -translate-x-1/2 rounded-pill bg-charcoal/80 px-3 py-1 text-center text-[11px] leading-relaxed text-white"
      >
        <!--
          The room step has no product list and cannot arrange anything, so it was being told
          to "add a product from the right" beside a column that was not there. Each screen
          gets its own sentence.
        -->
        <template v-if="openingsOnly">Soldaki simgelerden kapı ya da pencere seç, sonra odada tutup duvara sürükle.</template>
        <template v-else-if="state.items.length === 0">Odan boş. {{ workspace ? 'Sağdan' : 'Aşağıdan' }} ürün ekle ya da "Tasarıma göre yerleştir" de; kapıyı ve pencereyi tutup duvara sürükleyebilirsin.</template>
        <template v-else>Ürünü tutup sürükle, halkayla döndür. Sahneyi çevirmek için <kbd class="rounded-xs bg-bg-muted px-1 font-sans">boşluk</kbd> basılı tut. Tekerlek yakınlaştırır.</template>
      </p>

      <!-- Padded below the toolbars, which float over the top corners of the box. -->
      <RoomPlanSvg
        v-if="display === 'plan'"
        class="px-4 pt-16 pb-4"
        :geometry="geometry"
        :openings="openings"
        :items="state.items"
        :states="state.states"
        :selected-id="state.selectedId"
        :editable-openings="editable"
        @select="editor?.select($event)"
        @move-opening="(id, offset, wall) => emit('moveOpening', id, offset, wall)"
        @resize-opening="(id, offset, width) => emit('resizeOpening', id, offset, width)"
      />

      <!--
        The palette: windows, doors and balcony doors, each in the kinds a customer would name
        — single, double, three panes, a French balcony, a sliding door. One tap puts it in
        the room; then it is picked up and put on a wall like anything else.
      -->
      <div v-if="editable" class="absolute top-16 left-4 flex max-h-[calc(100%-9rem)] w-fit flex-col rounded-md bg-surface/95 p-1.5 shadow-sm backdrop-blur-sm" role="toolbar" aria-label="Kapı ve pencere ekle">
        <button
          type="button"
          class="flex items-center gap-2 rounded-sm px-1 py-1 text-xs font-medium text-ink-secondary transition-colors hover:bg-bg-muted"
          :aria-expanded="paletteOpen"
          @click="paletteOpen = !paletteOpen"
        >
          <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path :d="ICONS.openings" />
          </svg>
          <span>Kapı · pencere</span>
          <svg class="ml-auto size-4 shrink-0 text-muted transition-transform" :class="{ 'rotate-180': paletteOpen }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6 9l6 6 6-6" />
          </svg>
        </button>

        <!--
          A drawing, its name under it, and the size it comes in.

          It was a list of words — "Tek kanat", "Çift kanat", "Üçlü" — three times over, once
          per group, so the same three words appeared under three headings and the only thing
          telling a window from a balcony door was which heading it happened to be under. A
          drawing says which it is without being read, and the size under the name is how
          somebody chooses between two that look alike.

          Three across, because that is the width of the widest name and a grid that reflows
          puts the same kind in a different place every time the panel opens.
        -->
        <div v-if="paletteOpen" class="mt-1 overflow-y-auto">
          <template v-for="group in PALETTE" :key="group.type">
            <p class="px-1 pt-2 pb-1 text-[10px] font-medium tracking-wide text-muted uppercase">{{ group.label }}</p>

            <div class="grid grid-cols-3 gap-0.5">
              <button
                v-for="kind in group.kinds"
                :key="`${kind.type}-${kind.variant}`"
                type="button"
                class="flex w-[4.5rem] flex-col items-center gap-1 rounded-sm px-1 py-1.5 text-center text-[10px] leading-tight text-ink-secondary transition-colors hover:bg-bg-muted"
                :aria-label="`${kind.label} ${group.label.toLocaleLowerCase('tr-TR')} ekle`"
                @click="emit('addOpening', kind)"
              >
                <svg class="size-7 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path :d="kind.icon" />
                </svg>
                <span>{{ kind.label }}</span>
                <span class="text-[9px] text-muted tabular-nums">{{ Math.round(kind.width_mm / 10) }}×{{ Math.round(kind.height_mm / 10) }}</span>
              </button>
            </div>
          </template>

          <p class="max-w-[14rem] px-1 pt-2 text-[10px] leading-snug text-muted">
            Bas, odaya düşsün. Sonra tutup istediğin duvara sürükle.
          </p>
        </div>
      </div>

      <!--
        Measurements as HTML over the canvas rather than text drawn into it. Text in WebGL is
        either a texture that blurs the moment somebody zooms or a font atlas nobody wants to
        maintain for the sake of "185 cm".
      -->
      <div v-if="display === '3d'" class="pointer-events-none absolute inset-0">
        <template v-for="label in labels" :key="label.id">
          <!-- Wall names sit quietly at the top of each wall; measurements ride on the piece. -->
          <span
            v-if="label.towards === 'wall'"
            class="absolute -translate-x-1/2 -translate-y-full pb-1 text-[10px] font-medium tracking-wide text-ink-secondary/70 uppercase"
            :style="{ left: `${label.x}px`, top: `${label.y}px` }"
          >{{ label.text }}</span>
          <!--
            The angle, while the ring is being turned. Not behind the measurements switch: it
            is not a measurement of the room, it is a readout of the gesture in progress, and
            somebody who turned the labels off to look at their room still needs to know what
            angle they are dragging to.
          -->
          <span
            v-else-if="label.towards === 'angle'"
            class="absolute -translate-x-1/2 -translate-y-1/2 rounded-pill bg-accent-700 px-2.5 py-1 text-xs font-medium whitespace-nowrap text-white tabular-nums shadow-sm"
            :style="{ left: `${label.x}px`, top: `${label.y}px` }"
          >{{ label.text }}</span>
          <span
            v-else-if="showMeasurements"
            class="absolute -translate-x-1/2 -translate-y-1/2 rounded-pill bg-charcoal/85 px-2 py-0.5 text-[11px] whitespace-nowrap text-white tabular-nums"
            :style="{ left: `${label.x}px`, top: `${label.y}px` }"
          >{{ label.text }}</span>
        </template>
      </div>

      <!--
        The compass, when the labels are wrong.

        Four buttons rather than a turn: the customer said the walls only need renaming, and
        renaming is the common case — the room is drawn correctly and the reading guessed the
        wrong way round. Pressing one says "this drawn wall is the one that faces north", and
        the other three follow it clockwise. No measurement changes and nothing moves.

        Folded behind the compass itself, because it is looked at once per room and would
        otherwise be four more buttons over the floor for ever.
      -->
      <div v-if="editable && display === '3d'" class="absolute top-16 right-4 flex flex-col items-end gap-1">
        <button
          type="button"
          class="flex items-center gap-1.5 rounded-pill bg-surface/90 px-2.5 py-1.5 text-[11px] text-ink-secondary shadow-sm backdrop-blur-sm transition-colors hover:bg-surface"
          :aria-expanded="turningCompass"
          @click="turningCompass = !turningCompass"
        >
          <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7l2.5 5.5L12 17l-2.5-4.5z" />
          </svg>
          Kuzey
        </button>

        <div v-if="turningCompass" class="w-44 rounded-md bg-surface/95 p-2 shadow-sm backdrop-blur-sm">
          <p class="text-[10px] leading-snug text-muted">Kuzeye bakan duvar hangisi? Sadece isimler değişir, hiçbir şey yer değiştirmez.</p>

          <div class="mt-1.5 grid grid-cols-2 gap-1">
            <button
              v-for="wall in WALL_CHOICES"
              :key="wall.value"
              type="button"
              class="rounded-sm px-1.5 py-1 text-[11px] transition-colors"
              :class="northWall === wall.value ? 'bg-charcoal text-white' : 'text-ink-secondary hover:bg-bg-muted'"
              :aria-pressed="northWall === wall.value"
              @click="emit('turnCompass', wall.value); turningCompass = false"
            >
              {{ wall.label }}
            </button>
          </div>
        </div>
      </div>

      <!--
        What was just removed, and the way back.

        Above the tool bar rather than over it, because the hand that pressed Sil is still
        there and a button appearing under a finger is a button pressed by accident.
      -->
      <div
        v-if="removed !== null"
        class="absolute bottom-16 left-1/2 flex -translate-x-1/2 items-center gap-3 rounded-pill bg-charcoal px-3 py-1.5 text-xs whitespace-nowrap text-white shadow-md"
        role="status"
      >
        <span>{{ removed }} odadan çıktı.</span>
        <!--
          "Geri getir", not "Geri al": the undo button in the corner is already called that,
          and two controls with one name is two controls a screen reader cannot tell apart —
          and a customer, reading quickly, cannot either.
        -->
        <button type="button" class="rounded-pill bg-white/15 px-2.5 py-0.5 font-medium transition-colors hover:bg-white/25" @click="putBack">
          Geri getir
        </button>
      </div>

      <!--
        What the hand and the keyboard do, over the room.

        Every tool of this kind has one, and it is the difference between a customer who drags
        things about and one who is quick: nobody discovers by trying that Shift frees the
        rotation snap, or that space hands the gesture to the camera. The tooltips teach one
        key at a time; this is the whole list, on the key the whole world uses for it.
      -->
      <div
        v-if="helping"
        class="absolute inset-0 z-30 flex items-center justify-center bg-charcoal/40 p-4 backdrop-blur-[2px]"
        role="dialog"
        aria-modal="true"
        aria-label="Kısayollar"
        @click.self="helping = false"
      >
        <div class="max-h-full w-full max-w-3xl overflow-y-auto rounded-md bg-surface p-5 shadow-lg">
          <div class="flex items-start justify-between gap-4">
            <div>
              <h2 class="text-sm font-medium text-ink">Neyi nasıl yaparsın</h2>
              <p class="mt-0.5 text-xs text-muted">Hepsi isteğe bağlı — her şey fareyle de yapılır.</p>
            </div>

            <RoomToolButton icon="close" label="Kapat" keys="Esc" side="bottom" @click="helping = false" />
          </div>

          <div class="mt-4 grid gap-5 sm:grid-cols-3">
            <section v-for="group in HELP" :key="group.title">
              <h3 class="text-[10px] font-medium tracking-wide text-muted uppercase">{{ group.title }}</h3>
              <dl class="mt-2 space-y-1.5">
                <div v-for="[keys, what] in group.rows" :key="keys" class="text-xs leading-snug">
                  <dt class="font-medium text-ink">{{ keys }}</dt>
                  <dd class="text-ink-secondary">{{ what }}</dd>
                </div>
              </dl>
            </section>
          </div>
        </div>
      </div>

      <!--
        The tools for the selected piece, on the room itself, where the hand already is.
        The panel below still explains; this is for doing.
      -->
      <div
        v-if="editable && selected !== null && display === '3d'"
        class="absolute bottom-4 left-1/2 flex -translate-x-1/2 items-center gap-0.5 rounded-pill bg-surface/95 p-1 shadow-md backdrop-blur-sm"
        role="toolbar"
        :aria-label="`${selected.name} için araçlar`"
      >
        <RoomToolButton icon="rotateLeft" label="Sola çevir" keys="90°" @click="editor?.rotate(selected.id, -90)" />
        <RoomToolButton icon="rotateRight" label="Sağa çevir" keys="R" hint="Halkayı Shift ile serbest çevir" @click="editor?.rotate(selected.id, 90)" />
        <span class="mx-0.5 h-4 w-px bg-line" />
        <RoomToolButton icon="alignWall" label="Duvara yasla" hint="En yakın duvara, odaya dönük" @click="editor?.alignToWall(selected.id)" />
        <RoomToolButton icon="centre" label="Odanın ortasına" @click="editor?.centreInRoom(selected.id)" />
        <RoomToolButton icon="duplicate" label="Bir tane daha" keys="Ctrl+D" hint="Yanına koyar" @click="editor?.duplicate(selected.id)" />
        <RoomToolButton
          :icon="selected.locked ? 'unlock' : 'lock'"
          :label="selected.locked ? 'Kilidi aç' : 'Kilitle'"
          :active="selected.locked"
          hint="Kilitli eşya yerinden oynamaz"
          @click="editor?.toggleLock(selected.id)"
        />
        <RoomToolButton icon="focus" label="Buna yakınlaş" hint="Çift tıklamak da yakınlaştırır" @click="editor?.focusSelected()" />
        <span class="mx-0.5 h-4 w-px bg-line" />
        <RoomToolButton icon="remove" label="Odadan çıkar" keys="Delete" tone="danger" @click="remove(selected.id, selected.name)" />
      </div>

      <!--
        Zoom, as two buttons.
        The wheel and the pinch still work and are what most people will use; these are for
        the trackpad whose scroll the browser has taken, the stylus, and the hand that is
        already holding something. Bottom left, away from the view switcher, because they are
        pressed repeatedly and a button that moves under a repeated press is a misclick.
      -->
      <div v-if="display === '3d'" class="absolute top-1/2 right-4 flex -translate-y-1/2 flex-col gap-1 rounded-pill bg-surface/90 p-1 shadow-sm backdrop-blur-sm">
        <RoomToolButton icon="zoomIn" label="Yakınlaştır" keys="+" side="top" @click="editor?.zoom('in')" />
        <RoomToolButton icon="zoomOut" label="Uzaklaştır" keys="−" side="top" @click="editor?.zoom('out')" />
        <span class="mx-1.5 h-px bg-line" />
        <RoomToolButton icon="keys" label="Kısayollar" keys="?" side="top" @click="helping = true" />
      </div>

      <div class="absolute top-4 right-4 flex gap-1 rounded-pill bg-surface/90 p-1 backdrop-blur-sm">
        <RoomToolButton
          icon="plan"
          label="Kuşbakışı plan"
          hint="Odanın kağıt üstündeki hali"
          side="bottom"
          :active="display === 'plan'"
          @click="display = display === 'plan' ? '3d' : 'plan'"
        />

        <!--
          The room as the photographs measured it, beside the room we drew from a guess. The
          reading said this room was 3.8 by 4.5 metres one time and 4.5 by 5.0 the next; the
          reconstruction settles it, and seeing the two is how anybody would know.
        -->
        <RoomToolButton
          v-if="scanUrl"
          icon="scan"
          :label="scanFailed ? 'Tarama açılmadı' : 'Fotoğraftan ölçülen oda'"
          hint="Çizdiğimiz oda bir tahmin; bu ölçüm"
          side="bottom"
          :active="showingScan"
          @click="toggleScan"
        />

        <!--
          Off is for looking at the room rather than at the numbers — and for a screenshot,
          where four labels over a sofa are four labels in the picture.
        -->
        <RoomToolButton
          icon="ruler"
          label="Ölçüleri göster"
          hint="Seçili eşyanın çevresindeki boşluklar"
          side="bottom"
          :active="showMeasurements"
          @click="showMeasurements = !showMeasurements"
        />
        <RoomToolButton
          icon="fitRoom"
          label="Odayı sığdır"
          hint="Kamerayı odanın tamamını görecek yere getirir"
          side="bottom"
          :disabled="display === 'plan'"
          @click="editor?.frameRoom()"
        />

        <span class="my-1 w-px bg-line" />

        <RoomToolButton
          v-for="option in views"
          :key="option.value"
          :icon="option.icon"
          :label="option.label"
          :keys="option.keys"
          :hint="option.hint"
          side="bottom"
          :active="view === option.value"
          :disabled="display === 'plan'"
          @click="view = option.value"
        />
      </div>

      <div v-if="editable" class="absolute top-4 left-4 flex gap-1 rounded-pill bg-surface/90 p-1 backdrop-blur-sm">
        <RoomToolButton icon="undo" label="Geri al" keys="Ctrl+Z" side="bottom" :disabled="!state.canUndo" @click="editor?.undo()" />
        <RoomToolButton icon="redo" label="İleri al" keys="Ctrl+Shift+Z" side="bottom" :disabled="!state.canRedo" @click="editor?.redo()" />
      </div>

      <!--
        The room's size, when there is one.

        The scene falls back to a default box so that it has something to draw before anybody
        has measured anything — and it was printing that default in the corner as though it
        were the customer's room, under a guide asking them for the measurements. A number
        nobody gave should not be shown as a fact.
      -->
      <!--
        The measurements, and a way to correct them without leaving the room.

        They were a line of grey text, and correcting them meant scrolling a column of panels
        beside the scene to find "Ölçüleri düzelt". But the moment somebody knows the room is
        wrong is the moment they are looking at it — a sofa that will not fit, a wall that is
        obviously too short — so the correction belongs where the wrongness is.

        Centimetres in the boxes, because that is what a tape measure reads.
      -->
      <div class="absolute right-4 bottom-4 text-right text-xs text-muted">
        <form v-if="sizing && editable" class="flex items-center gap-1 rounded-md bg-surface/95 p-1.5 backdrop-blur-sm" @submit.prevent="applySize">
          <label class="sr-only" for="room-width">Genişlik (cm)</label>
          <input
            id="room-width"
            v-model="size.width"
            type="number"
            inputmode="numeric"
            class="w-16 rounded-sm border border-line bg-surface px-1.5 py-1 text-right text-xs text-ink tabular-nums"
            autofocus
          >
          <span aria-hidden="true">×</span>
          <label class="sr-only" for="room-length">Derinlik (cm)</label>
          <input
            id="room-length"
            v-model="size.length"
            type="number"
            inputmode="numeric"
            class="w-16 rounded-sm border border-line bg-surface px-1.5 py-1 text-right text-xs text-ink tabular-nums"
          >
          <span class="pl-1" aria-hidden="true">tavan</span>
          <label class="sr-only" for="room-height">Tavan yüksekliği (cm)</label>
          <input
            id="room-height"
            v-model="size.height"
            type="number"
            inputmode="numeric"
            class="w-16 rounded-sm border border-line bg-surface px-1.5 py-1 text-right text-xs text-ink tabular-nums"
          >
          <span class="pr-1">cm</span>
          <button
            type="submit"
            class="rounded-pill bg-charcoal px-2.5 py-1 text-xs text-white disabled:opacity-40"
            :disabled="!sizeIsSane"
            :title="sizeIsSane ? 'Ölçüleri kaydet' : 'Oda 1–20 m, tavan 2–5 m olmalı'"
          >
            Kaydet
          </button>
          <button type="button" class="rounded-pill px-2 py-1 text-xs text-ink-secondary hover:bg-bg-muted" @click="sizing = false">
            Vazgeç
          </button>
        </form>

        <button
          v-else-if="editable"
          type="button"
          class="rounded-pill px-2 py-1 transition-colors hover:bg-surface/80 hover:text-ink-secondary"
          title="Ölçüleri düzelt"
          @click="openSizing"
        >
          <template v-if="measured">
            {{ (geometry.width_mm / 1000).toFixed(2) }} × {{ (geometry.length_mm / 1000).toFixed(2) }} m ·
            tavan {{ (geometry.height_mm / 1000).toFixed(2) }} m
          </template>
          <template v-else>Ölçü bekleniyor — düzelt</template>
        </button>

        <p v-else>
          <template v-if="measured">
            {{ (geometry.width_mm / 1000).toFixed(2) }} × {{ (geometry.length_mm / 1000).toFixed(2) }} m ·
            tavan {{ (geometry.height_mm / 1000).toFixed(2) }} m
          </template>
          <template v-else>Ölçü bekleniyor</template>
        </p>
      </div>
    </div>

    <!-- Column children must not shrink: a flex column with overflow squeezes them and the guide's sentence gets clipped. -->
    <div v-if="editable" :class="workspace ? 'flex min-h-0 flex-col gap-3 overflow-y-auto pr-1 [&>*]:shrink-0' : 'grid gap-3 md:grid-cols-2'">
      <!-- What the page wants done first, above the selection: arranging by the design. -->
      <slot name="side-start" />

      <!-- What is selected, and everything that can be done to it. Furniture only, so the room step hides it. -->
      <div v-if="!openingsOnly" class="rounded-md border border-line bg-surface p-4">
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
            The handles are on the piece itself — arrows to slide it, a ring to turn it, both
            at once. Said here because nothing else says it: Shift frees the ring from 15°
            steps, R is a quarter turn.
          -->
          <p class="mt-2 text-xs text-muted">
            Oklarla taşıyın, yeşil halkayla döndürün (15° adımlar; Shift ile serbest).
          </p>

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
      <div v-if="!openingsOnly" class="rounded-md border border-line bg-surface p-4">
        <div class="flex items-center justify-between">
          <p class="text-sm font-medium text-ink">
            Odadaki ürünler ({{ state.items.length }})
          </p>
          <span v-if="state.unsaved" class="text-xs text-muted">Kaydediliyor…</span>
        </div>

        <ul class="mt-2 max-h-64 space-y-1 overflow-y-auto">
          <li v-for="item in state.items" :key="item.id">
            <button
              type="button"
              class="flex w-full items-center gap-2.5 rounded-sm px-2 py-1.5 text-left text-xs transition-colors"
              :class="item.id === state.selectedId ? 'bg-bg-muted' : 'hover:bg-bg-muted'"
              @click="editor?.select(item.id)"
              @dblclick="editor?.select(item.id); editor?.focusSelected()"
            >
              <span class="grid size-9 shrink-0 place-items-center overflow-hidden rounded-sm bg-bg-muted">
                <img v-if="item.image_url" :src="item.image_url" :alt="item.name" class="size-full object-cover" draggable="false">
                <span v-else class="text-[10px] text-muted">3B</span>
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-ink">{{ item.name }}</span>
                <span class="block truncate text-[11px] text-muted">
                  {{ item.width_mm && item.depth_mm ? `${formatDistance(item.width_mm)} × ${formatDistance(item.depth_mm)}` : 'Ölçüsüz' }}
                  <template v-if="item.locked"> · kilitli</template>
                  <template v-if="state.states.get(item.id) === 'blocked'"> · sığmıyor</template>
                  <template v-else-if="state.states.get(item.id) === 'warning'"> · pencerenin önünde</template>
                </span>
              </span>
              <span class="shrink-0 text-right tabular-nums" :class="item.price ? 'text-ink' : 'text-muted'">
                {{ item.price?.formatted ?? '—' }}
              </span>
            </button>
          </li>
        </ul>

        <!-- The running total, and what it leaves out (K20). -->
        <div v-if="state.items.length > 0" class="mt-3 flex items-baseline justify-between border-t border-line pt-3">
          <span class="text-xs text-muted">
            Toplam
            <template v-if="total.unpriced > 0"> · {{ total.unpriced }} ürünün fiyatı yok</template>
          </span>
          <span class="text-sm font-medium tabular-nums text-ink">{{ total.formatted }}</span>
        </div>

        <p v-if="problems.length > 0" class="mt-3 text-xs text-muted">
          {{ problems.length }} ürün için uyarı var. Kırmızı olanlar bu odaya bu şekilde
          yerleşmiyor; sipariş vermeden önce düzeltilmesi gerekir.
        </p>

        <!-- What the page wants done with the room as it stands: render it, buy it. -->
        <div v-if="$slots.actions" class="mt-3 flex flex-wrap gap-2 border-t border-line pt-3">
          <slot name="actions" />
        </div>
      </div>

      <!-- Whatever else the page wants beside the room: doors and windows, the catalogue. -->
      <slot name="side" />
    </div>
  </div>
</template>
