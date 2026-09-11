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
import type { LayoutItem, RoomGeometry, RoomOpening } from '~/room3d/types'

const props = withDefaults(defineProps<{
  geometry: RoomGeometry
  openings: RoomOpening[]
  items: LayoutItem[]
  states: Map<string, string>
  selectedId?: string | null
}>(), {
  selectedId: null,
})

const emit = defineEmits<{ select: [id: string | null] }>()

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
  x1: number
  y1: number
  x2: number
  y2: number
  swings: boolean
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
    .filter(opening => opening.wall === wall && opening.offset_mm !== null && opening.width_mm !== null)
    .map(opening => ({
      from: opening.offset_mm ?? 0,
      to: (opening.offset_mm ?? 0) + (opening.width_mm ?? 0),
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
    const offset = opening.offset_mm
    const span = opening.width_mm

    if (offset === null || span === null) {
      continue
    }

    const swings = opening.type === 'door' || opening.type === 'balcony_door'

    switch (opening.wall) {
      case 'north':
        drawn.push({ x1: offset, y1: 0, x2: offset + span, y2: 0, swings })
        break
      case 'south':
        drawn.push({ x1: offset, y1: length, x2: offset + span, y2: length, swings })
        break
      case 'west':
        drawn.push({ x1: 0, y1: offset, x2: 0, y2: offset + span, swings })
        break
      case 'east':
        drawn.push({ x1: width, y1: offset, x2: width, y2: offset + span, swings })
        break
    }
  }

  return drawn
})

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
    :viewBox="viewBox"
    class="block h-full w-full bg-bg-muted"
    role="img"
    :aria-label="`Oda planı, ${(geometry.width_mm / 1000).toFixed(2)} metreye ${(geometry.length_mm / 1000).toFixed(2)} metre`"
    @click="emit('select', null)"
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
        v-for="(gap, index) in gaps"
        :key="`o${index}`"
        :x1="gap.x1"
        :y1="gap.y1"
        :x2="gap.x2"
        :y2="gap.y2"
        :class="gap.swings ? 'stroke-warning' : 'stroke-accent-500'"
      />
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
