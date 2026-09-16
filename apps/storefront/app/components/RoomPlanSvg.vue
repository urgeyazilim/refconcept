<script setup lang="ts">
/**
 * The room as a plan, drawn to scale.
 *
 * The 3D view answers how a room feels; this answers where things are, and they are
 * genuinely different questions. In perspective the sofa nearest the camera looks larger
 * than the wardrobe behind it, which is honest about the experience and useless for judging
 * whether the walkway is wide enough. Here two gaps that measure the same look the same.
 *
 * SVG rather than the WebGL canvas from above, for one reason: text. A plan is mostly
 * labels — the name of a piece, a measurement, which wall is which — and text in WebGL is
 * either a texture that goes blurry the moment somebody zooms or a font atlas nobody wants
 * to maintain for the sake of "185 cm". In SVG it is text, at any zoom, selectable, and
 * readable by a screen reader.
 *
 * Coordinates are millimetres throughout, mapped straight onto the viewBox, so nothing here
 * scales or converts: a sofa 2200 mm wide is 2200 units wide, and the browser handles the
 * rest. The origin is the corner where the north and west walls meet, matching the API.
 */
import { formatDistance } from '~/room3d/MeasurementEngine'
import { footprintOf, isMeasured } from '~/room3d/footprint'
import { leavesOf, variantOf } from '~/room3d/openings'
import type { LayoutItem, RoomGeometry, RoomOpening, WallName } from '~/room3d/types'

const props = withDefaults(defineProps<{
  geometry: RoomGeometry
  openings: RoomOpening[]
  items: LayoutItem[]
  states: Map<string, string>
  selectedId?: string | null
  /** Whether doors and windows can be dragged along their wall. */
  editableOpenings?: boolean
}>(), {
  selectedId: null,
  editableOpenings: false,
})

const emit = defineEmits<{
  select: [id: string | null]
  /** A door or window was dragged along its wall and let go at this offset. */
  moveOpening: [id: string, offsetMm: number, wall: WallName]
  /** A door or window's end was dragged: it is this wide now, starting here. */
  resizeOpening: [id: string, offsetMm: number, widthMm: number]
}>()

const svg = ref<SVGSVGElement | null>(null)

/**
 * A door or window being dragged along its wall.
 *
 * The plan is where openings get corrected: the analysis puts a window 4.2 m along a 4.85 m
 * wall and the customer, who can see it is in the middle, slides it there. Along the wall
 * only — an opening cannot leave its wall by being dragged, and the corners stop it.
 */
const dragging = ref<{ id: string, wall: WallName, startWall: WallName, startOffset: number, startAlong: number, offset: number } | null>(null)

/**
 * The wall nearest the pointer. A door dragged towards another wall goes onto it — the
 * customer is not asked to name walls, they put things where they are.
 */
function nearestWall(point: { x: number, y: number }): WallName {
  const { width_mm: width, length_mm: length } = props.geometry

  const distances: Array<[WallName, number]> = [
    ['north', Math.abs(point.y)],
    ['south', Math.abs(length - point.y)],
    ['west', Math.abs(point.x)],
    ['east', Math.abs(width - point.x)],
  ]

  distances.sort((a, b) => a[1] - b[1])

  return distances[0]![0]
}

/** Where the pointer is, in the plan's own millimetres. */
function planPoint(event: PointerEvent): { x: number, y: number } | null {
  const element = svg.value

  if (element === null) {
    return null
  }

  const matrix = element.getScreenCTM()

  if (matrix === null) {
    return null
  }

  const point = new DOMPoint(event.clientX, event.clientY).matrixTransform(matrix.inverse())

  return { x: point.x, y: point.y }
}

function along(wall: string, point: { x: number, y: number }): number {
  return wall === 'north' || wall === 'south' ? point.x : point.y
}

function startOpeningDrag(event: PointerEvent, opening: RoomOpening): void {
  if (!props.editableOpenings || opening.wall === null || opening.offset_mm === null || opening.width_mm === null) {
    return
  }

  const point = planPoint(event)

  if (point === null) {
    return
  }

  dragging.value = {
    id: opening.id,
    wall: opening.wall,
    startWall: opening.wall,
    startOffset: opening.offset_mm,
    startAlong: along(opening.wall, point),
    offset: opening.offset_mm,
  }

  svg.value?.setPointerCapture(event.pointerId)
}

function moveOpeningDrag(event: PointerEvent): void {
  const drag = dragging.value

  if (drag === null) {
    return
  }

  const point = planPoint(event)
  const opening = props.openings.find(candidate => candidate.id === drag.id)

  if (point === null || opening === undefined || opening.width_mm === null) {
    return
  }

  const wall = nearestWall(point)
  const span = wall === 'north' || wall === 'south' ? props.geometry.width_mm : props.geometry.length_mm

  // Along the wall it started on the grab keeps its offset; on another wall the opening
  // sits centred under the pointer, which is where somebody dragging it expects it.
  const desired = wall === drag.startWall
    ? drag.startOffset + (along(wall, point) - drag.startAlong)
    : along(wall, point) - opening.width_mm / 2

  // Inside its wall, to the corners and no further; rounded to the centimetre a plan works in.
  drag.wall = wall
  drag.offset = Math.round(Math.max(0, Math.min(span - opening.width_mm, desired)) / 10) * 10
}

function endOpeningDrag(event: PointerEvent): void {
  const drag = dragging.value

  if (drag === null) {
    return
  }

  dragging.value = null

  if (svg.value?.hasPointerCapture(event.pointerId)) {
    svg.value.releasePointerCapture(event.pointerId)
  }

  if (drag.offset !== drag.startOffset || drag.wall !== drag.startWall) {
    emit('moveOpening', drag.id, drag.offset, drag.wall)
  }
}

/** An opening's offset as it is being dragged, or as it is. */
function offsetOf(opening: RoomOpening): number | null {
  if (resizing.value?.id === opening.id) {
    return resizing.value.offset
  }

  return dragging.value?.id === opening.id ? dragging.value.offset : opening.offset_mm
}

/** An opening's wall as it is being dragged, or as it is. */
function wallOf(opening: RoomOpening): WallName | null {
  return dragging.value?.id === opening.id ? dragging.value.wall : opening.wall
}

/**
 * A door or window being made wider or narrower by one of its ends.
 *
 * The far end stays where it is; the end in the hand slides along the wall. A door narrower
 * than 40 cm is not a door, and nothing grows past the corner.
 */
const resizing = ref<{ id: string, wall: WallName, end: 'start' | 'end', startOffset: number, startWidth: number, startAlong: number, offset: number, width: number } | null>(null)

const MIN_OPENING_MM = 400

function startOpeningResize(event: PointerEvent, opening: RoomOpening, end: 'start' | 'end'): void {
  if (!props.editableOpenings || opening.wall === null || opening.offset_mm === null || opening.width_mm === null) {
    return
  }

  const point = planPoint(event)

  if (point === null) {
    return
  }

  resizing.value = {
    id: opening.id,
    wall: opening.wall,
    end,
    startOffset: opening.offset_mm,
    startWidth: opening.width_mm,
    startAlong: along(opening.wall, point),
    offset: opening.offset_mm,
    width: opening.width_mm,
  }

  svg.value?.setPointerCapture(event.pointerId)
}

function moveOpeningResize(event: PointerEvent): boolean {
  const resize = resizing.value

  if (resize === null) {
    return false
  }

  const point = planPoint(event)

  if (point === null) {
    return true
  }

  const span = resize.wall === 'north' || resize.wall === 'south' ? props.geometry.width_mm : props.geometry.length_mm
  const delta = along(resize.wall, point) - resize.startAlong
  const far = resize.startOffset + resize.startWidth

  if (resize.end === 'end') {
    resize.width = Math.round(Math.max(MIN_OPENING_MM, Math.min(span - resize.startOffset, resize.startWidth + delta)) / 10) * 10
  }
  else {
    const offset = Math.round(Math.max(0, Math.min(far - MIN_OPENING_MM, resize.startOffset + delta)) / 10) * 10
    resize.offset = offset
    resize.width = far - offset
  }

  return true
}

function endOpeningResize(event: PointerEvent): boolean {
  const resize = resizing.value

  if (resize === null) {
    return false
  }

  resizing.value = null

  if (svg.value?.hasPointerCapture(event.pointerId)) {
    svg.value.releasePointerCapture(event.pointerId)
  }

  if (resize.offset !== resize.startOffset || resize.width !== resize.startWidth) {
    emit('resizeOpening', resize.id, resize.offset, resize.width)
  }

  return true
}

/** An opening's width as it is being resized, or as it is. */
function widthOf(opening: RoomOpening): number | null {
  return resizing.value?.id === opening.id ? resizing.value.width : opening.width_mm
}

/** Room for the dimension lines and wall thickness outside the floor itself. */
const MARGIN_MM = 700

/** Drawn thickness of a wall. Not a measurement of the customer's walls — a plan convention. */
const WALL_MM = 100

const viewBox = computed(() => {
  const { width_mm: width, length_mm: length } = props.geometry

  return `${-MARGIN_MM} ${-MARGIN_MM} ${width + MARGIN_MM * 2} ${length + MARGIN_MM * 2}`
})

/**
 * Each wall as a line, broken where the doors and windows are.
 *
 * Drawn as segments rather than a rectangle with white boxes painted over it: a gap painted
 * on top is a gap that disappears the moment somebody prints the plan or the background is
 * any colour but the one it was painted in.
 */
interface Segment {
  x1: number
  y1: number
  x2: number
  y2: number
}

interface Opening {
  opening: RoomOpening
  x1: number
  y1: number
  x2: number
  y2: number
  swings: boolean
  /** How many leaves or panes across; drawn as ticks so a double window reads as one. */
  leaves: number
  sliding: boolean
}

const walls = computed<Segment[]>(() => {
  const { width_mm: width, length_mm: length } = props.geometry

  const segments: Segment[] = []

  // North and south run along x; west and east along z. The gaps are cut out of each in
  // turn, so an opening only ever breaks the wall it belongs to.
  segments.push(...cut('north', 0, width, along => ({ x1: along.from, y1: 0, x2: along.to, y2: 0 })))
  segments.push(...cut('south', 0, width, along => ({ x1: along.from, y1: length, x2: along.to, y2: length })))
  segments.push(...cut('west', 0, length, along => ({ x1: 0, y1: along.from, x2: 0, y2: along.to })))
  segments.push(...cut('east', 0, length, along => ({ x1: width, y1: along.from, x2: width, y2: along.to })))

  return segments
})

function cut(
  wall: string,
  start: number,
  end: number,
  toSegment: (along: { from: number, to: number }) => Segment,
): Segment[] {
  const gaps = props.openings
    .filter(opening => wallOf(opening) === wall && offsetOf(opening) !== null && opening.width_mm !== null)
    .map(opening => ({
      from: offsetOf(opening) ?? 0,
      to: (offsetOf(opening) ?? 0) + (widthOf(opening) ?? 0),
    }))
    .sort((a, b) => a.from - b.from)

  const segments: Segment[] = []
  let cursor = start

  for (const gap of gaps) {
    if (gap.from > cursor) {
      segments.push(toSegment({ from: cursor, to: Math.min(gap.from, end) }))
    }

    cursor = Math.max(cursor, gap.to)
  }

  if (cursor < end) {
    segments.push(toSegment({ from: cursor, to: end }))
  }

  return segments
}

/** The openings themselves, drawn thinner and in their own colour so they read as gaps. */
const gaps = computed<Opening[]>(() => {
  const { width_mm: width, length_mm: length } = props.geometry

  const drawn: Opening[] = []

  for (const opening of props.openings) {
    const offset = offsetOf(opening)
    const span = widthOf(opening)

    if (offset === null || span === null) {
      continue
    }

    const variant = variantOf(opening)
    const sliding = variant === 'sliding'
    const swings = (opening.type === 'door' || opening.type === 'balcony_door') && !sliding
    const leaves = leavesOf(variant)

    switch (wallOf(opening)) {
      case 'north':
        drawn.push({ opening, x1: offset, y1: 0, x2: offset + span, y2: 0, swings, leaves, sliding })
        break
      case 'south':
        drawn.push({ opening, x1: offset, y1: length, x2: offset + span, y2: length, swings, leaves, sliding })
        break
      case 'west':
        drawn.push({ opening, x1: 0, y1: offset, x2: 0, y2: offset + span, swings, leaves, sliding })
        break
      case 'east':
        drawn.push({ opening, x1: width, y1: offset, x2: width, y2: offset + span, swings, leaves, sliding })
        break
    }
  }

  return drawn
})

interface Mark { x1: number, y1: number, x2: number, y2: number }

/** Where the leaves or panes meet: a short tick across the opening at each division. */
function ticks(gap: Opening): Mark[] {
  const horizontal = gap.y1 === gap.y2
  const half = WALL_MM * 0.9
  const marks: Mark[] = []

  for (let index = 1; index < gap.leaves; index++) {
    const t = index / gap.leaves
    const x = gap.x1 + (gap.x2 - gap.x1) * t
    const y = gap.y1 + (gap.y2 - gap.y1) * t

    marks.push(horizontal ? { x1: x, y1: y - half, x2: x, y2: y + half } : { x1: x - half, y1: y, x2: x + half, y2: y })
  }

  return marks
}

/** A sliding door's second panel, drawn just inside the first from the middle to the end. */
function slidingPanel(gap: Opening): Mark {
  const horizontal = gap.y1 === gap.y2
  const inward = (horizontal ? gap.y1 === 0 : gap.x1 === 0) ? 1 : -1
  const step = WALL_MM * 0.55 * inward
  const midX = (gap.x1 + gap.x2) / 2
  const midY = (gap.y1 + gap.y2) / 2

  return horizontal
    ? { x1: midX, y1: midY + step, x2: gap.x2, y2: gap.y2 + step }
    : { x1: midX + step, y1: midY, x2: gap.x2 + step, y2: gap.y2 }
}

/** Every piece as a rectangle, already rotated, with where its label goes. */
const pieces = computed(() => props.items.map((item) => {
  const footprint = footprintOf(item)

  // The unrotated size, because the rectangle itself is rotated by the transform below —
  // rotating a footprint that has already been swapped would turn it back.
  const width = isMeasured(item) ? (item.width_mm ?? 0) : 600
  const depth = isMeasured(item) ? (item.depth_mm ?? 0) : 600

  return {
    item,
    width,
    depth,
    x: item.position_x_mm - width / 2,
    y: item.position_z_mm - depth / 2,
    // SVG turns clockwise in a y-down plan, which is the direction the stored degrees go.
    transform: `rotate(${item.rotation_y_deg} ${item.position_x_mm} ${item.position_z_mm})`,
    label: item.name,
    size: `${formatDistance(footprint.width)} × ${formatDistance(footprint.depth)}`,
    measured: isMeasured(item),
    state: props.states.get(item.id) ?? 'ok',
  }
}))

/**
 * How big the labels are.
 *
 * The viewBox is in millimetres, so a font size of 1 would be a millimetre tall. 150 is
 * roughly the height of a label on a printed plan of a room this size — big enough to read,
 * small enough to fit inside a coffee table.
 */
const LABEL_MM = 150
</script>

<template>
  <!--
    `viewBox`, not `view-box`. SVG attribute names are case-sensitive and Vue passes them
    through as written: a hyphenated one is silently ignored, the user unit becomes a CSS
    pixel, and a 4850 mm room is drawn 4850 pixels wide — which looks like a broken plan
    rather than a missing capital B.
  -->
  <svg
    ref="svg"
    :viewBox="viewBox"
    class="block h-full w-full bg-bg-muted"
    :class="{ 'touch-none': editableOpenings }"
    role="img"
    :aria-label="`Oda planı, ${(geometry.width_mm / 1000).toFixed(2)} metreye ${(geometry.length_mm / 1000).toFixed(2)} metre`"
    @click="emit('select', null)"
    @pointermove="moveOpeningResize($event) || moveOpeningDrag($event)"
    @pointerup="endOpeningResize($event) || endOpeningDrag($event)"
    @pointercancel="endOpeningResize($event) || endOpeningDrag($event)"
  >
    <!-- The floor. Clicking it is how somebody deselects. -->
    <rect x="0" y="0" :width="geometry.width_mm" :height="geometry.length_mm" class="fill-surface" />

    <g :stroke-width="WALL_MM" stroke-linecap="square" class="stroke-charcoal">
      <line v-for="(wall, index) in walls" :key="`w${index}`" :x1="wall.x1" :y1="wall.y1" :x2="wall.x2" :y2="wall.y2" />
    </g>

    <!--
      Openings drawn in place of the wall rather than over it: a door is a way through, and a
      plan that paints a white box over a black line is a plan that loses the door the moment
      it is printed.
    -->
    <g :stroke-width="WALL_MM * 0.6" stroke-linecap="butt">
      <line
        v-for="gap in gaps"
        :key="gap.opening.id"
        :x1="gap.x1"
        :y1="gap.y1"
        :x2="gap.x2"
        :y2="gap.y2"
        :class="[gap.swings ? 'stroke-warning' : 'stroke-accent-500', { 'cursor-move': editableOpenings }]"
        @pointerdown.stop="startOpeningDrag($event, gap.opening)"
        @click.stop
      />
      <!-- Where the leaves meet, and a sliding door's second panel: so two kinds of window differ on paper too. -->
      <g class="pointer-events-none stroke-charcoal" :stroke-width="WALL_MM * 0.25">
        <template v-for="gap in gaps" :key="`t${gap.opening.id}`">
          <line v-for="(tick, index) in ticks(gap)" :key="index" :x1="tick.x1" :y1="tick.y1" :x2="tick.x2" :y2="tick.y2" />
          <line v-if="gap.sliding" :x1="slidingPanel(gap).x1" :y1="slidingPanel(gap).y1" :x2="slidingPanel(gap).x2" :y2="slidingPanel(gap).y2" :stroke-width="WALL_MM * 0.5" class="stroke-accent-500" />
        </template>
      </g>
      <!-- A wider, invisible handle over each opening: a 60 mm line is a hard thing to grab. -->
      <line
        v-for="gap in gaps"
        :key="`h${gap.opening.id}`"
        :x1="gap.x1"
        :y1="gap.y1"
        :x2="gap.x2"
        :y2="gap.y2"
        :stroke-width="WALL_MM * 3"
        stroke="transparent"
        :class="{ 'cursor-move': editableOpenings }"
        @pointerdown.stop="startOpeningDrag($event, gap.opening)"
        @click.stop
      />
      <!-- A handle at each end: take it to make the door or window wider or narrower. -->
      <template v-if="editableOpenings">
        <template v-for="gap in gaps" :key="`r${gap.opening.id}`">
          <circle :cx="gap.x1" :cy="gap.y1" :r="WALL_MM * 0.9" class="cursor-ew-resize fill-surface stroke-accent-600" :stroke-width="WALL_MM * 0.25" @pointerdown.stop="startOpeningResize($event, gap.opening, 'start')" @click.stop />
          <circle :cx="gap.x2" :cy="gap.y2" :r="WALL_MM * 0.9" class="cursor-ew-resize fill-surface stroke-accent-600" :stroke-width="WALL_MM * 0.25" @pointerdown.stop="startOpeningResize($event, gap.opening, 'end')" @click.stop />
        </template>
      </template>
    </g>

    <!-- Furniture. -->
    <g>
      <g
        v-for="piece in pieces"
        :key="piece.item.id"
        :transform="piece.transform"
        class="cursor-pointer"
        @click.stop="emit('select', piece.item.id)"
      >
        <rect
          :x="piece.x"
          :y="piece.y"
          :width="piece.width"
          :height="piece.depth"
          :stroke-width="piece.item.id === selectedId ? 60 : 25"
          :stroke-dasharray="piece.measured ? undefined : '120 90'"
          :class="{
            'fill-neutral-200 stroke-neutral-700': piece.state === 'ok' && piece.measured,
            'fill-warning-subtle stroke-warning': piece.state === 'warning',
            'fill-danger-subtle stroke-danger': piece.state === 'blocked',
            'fill-transparent stroke-neutral-400': !piece.measured,
            'stroke-accent-600': piece.item.id === selectedId,
          }"
        />

        <text
          :x="piece.item.position_x_mm"
          :y="piece.item.position_z_mm"
          :font-size="LABEL_MM"
          text-anchor="middle"
          class="pointer-events-none fill-charcoal"
        >{{ piece.label }}</text>

        <text
          :x="piece.item.position_x_mm"
          :y="piece.item.position_z_mm + LABEL_MM * 1.2"
          :font-size="LABEL_MM * 0.8"
          text-anchor="middle"
          class="pointer-events-none fill-ink-secondary"
        >{{ piece.measured ? piece.size : 'ölçüsüz' }}</text>
      </g>
    </g>

    <!--
      The room's own measurements, along the top and the left. On a plan these are what
      somebody checks first, and they are the two numbers the whole screen rests on.
    -->
    <g class="stroke-ink-secondary" stroke-width="12">
      <line :x1="0" :y1="-MARGIN_MM / 2" :x2="geometry.width_mm" :y2="-MARGIN_MM / 2" />
      <line :x1="-MARGIN_MM / 2" :y1="0" :x2="-MARGIN_MM / 2" :y2="geometry.length_mm" />
    </g>

    <text
      :x="geometry.width_mm / 2"
      :y="-MARGIN_MM / 2 - LABEL_MM * 0.5"
      :font-size="LABEL_MM"
      text-anchor="middle"
      class="fill-ink-secondary"
    >{{ formatDistance(geometry.width_mm) }}</text>

    <text
      :x="-MARGIN_MM / 2 - LABEL_MM * 0.5"
      :y="geometry.length_mm / 2"
      :font-size="LABEL_MM"
      text-anchor="middle"
      :transform="`rotate(-90 ${-MARGIN_MM / 2 - LABEL_MM * 0.5} ${geometry.length_mm / 2})`"
      class="fill-ink-secondary"
    >{{ formatDistance(geometry.length_mm) }}</text>
  </svg>
</template>
