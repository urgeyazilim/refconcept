<script setup lang="ts">
/**
 * The studio's ten steps, and where the customer is in them.
 *
 * One strip at the top of every room screen — the room, the plan, the design — so
 * somebody always knows what they have done, what they are doing and what comes next. A
 * done step has a tick and stays a link; the current step is the one lit; the ones ahead
 * are visible and quiet. Nothing here is a button that does work: it is a map.
 *
 * The ten are the product owner's, in their order (docs/product/ODA_STUDYOSU_KURALLARI.md
 * §2): photographs, the furniture out, the room understood, what you want, the design, the
 * 3D arrangement, saved, the render, the 360 tour, the purchase. None is skipped and none is
 * hidden; the guide walks them one at a time.
 */
export type StudioStep = 'photo' | 'plate' | 'recognise' | 'propose' | 'design' | 'edit' | 'save' | 'render' | 'video' | 'buy'

const props = defineProps<{
  projectId: string
  roomId: string
  /** The step this screen is about. */
  current: StudioStep
  /** Which steps are done, as far as this screen knows. Unknown steps are shown quiet. */
  done: Partial<Record<StudioStep, boolean>>
  /** The design the design steps link to, when the screen knows one. */
  designId?: string | null
  /**
   * The steps this screen shows itself. With `selectable`, these become buttons that emit
   * `select` when done or current; the steps on other screens stay links.
   */
  own?: StudioStep[]
  selectable?: boolean
}>()

const emit = defineEmits<{ (event: 'select', step: StudioStep): void }>()

// Resolved once: a string in `:is` only finds globally registered components, and NuxtLink
// is auto-imported, not registered — the strip rendered `<nuxtlink>` elements nobody could click.
const NuxtLink = resolveComponent('NuxtLink')

/** The room screen's own steps, unless the screen says otherwise. */
const own = computed<StudioStep[]>(() => props.own ?? ['photo', 'plate', 'recognise', 'propose'])

/**
 * Every own step opens once there is a photograph: somebody who does not want to wait for
 * the reading can type the size themselves, or look back at what was read.
 */
const choosable = (key: StudioStep): boolean =>
  props.selectable === true && own.value.includes(key) && (props.done.photo === true || key === props.current)

const room = computed(() => `/projects/${props.projectId}/rooms/${props.roomId}`)

/**
 * The design screen's address, with the step's own anchor on it.
 *
 * Built here rather than glued together at each use, because it was glued together wrongly:
 * with no design yet the base already ended in an anchor and every link came out as
 * ".../rooms/x#tasarim#tasarim". A room with no design sends all four of its steps back to
 * the room, which is where the design is asked for.
 */
const design = (at: string): string => (props.designId
  ? `${room.value}/designs/${props.designId}${at}`
  : `${room.value}#istekler`)

const steps = computed(() => [
  { key: 'photo' as const, label: 'Fotoğraf', to: `${room.value}#fotograf` },
  { key: 'plate' as const, label: 'Eşyalar', to: `${room.value}#esyalar` },
  { key: 'recognise' as const, label: 'Oda', to: `${room.value}#oda` },
  { key: 'propose' as const, label: 'İstekler', to: `${room.value}#istekler` },
  { key: 'design' as const, label: 'Tasarım', to: design('#tasarim') },
  // Six and seven are the same screen in two states: arranging, and arranged. The anchor is
  // what tells the plan which of the two the customer asked for.
  { key: 'edit' as const, label: '3B', to: `${room.value}/plan#duzenle` },
  { key: 'save' as const, label: 'Kayıt', to: `${room.value}/plan#kayit` },
  { key: 'render' as const, label: 'Render', to: design('#render') },
  { key: 'video' as const, label: '360', to: design('#video') },
  { key: 'buy' as const, label: 'Satın al', to: design('#alisveris') },
])

const index = (key: StudioStep): number => steps.value.findIndex(step => step.key === key)

/**
 * The last step anybody has finished; everything before it counts as finished too.
 *
 * Each screen works out for itself what it can see, and they saw different things: the room
 * screen left "Eşyalar" on its number because the customer never emptied the room, while the
 * design screen ticked it because a design existed. Same room, two answers, one strip. A
 * stepper is read as a road — you cannot be at step five without having passed step two — so
 * the tick is monotonic, and the screens no longer have to agree about the middle.
 */
const furthest = computed(() => {
  let at = -1

  steps.value.forEach((step, index) => {
    if (props.done[step.key] === true) at = index
  })

  return at
})

function stateOf(key: StudioStep): 'done' | 'current' | 'ahead' {
  if (key === props.current) {
    return 'current'
  }

  if (props.done[key] === true || index(key) < furthest.value) {
    return 'done'
  }

  return 'ahead'
}

/** What to say at the right: the next thing to do, in a few words. */
const next = computed(() => {
  const first = steps.value.find(step => props.done[step.key] !== true && step.key !== props.current)

  return first === undefined ? null : first
})
</script>

<template>
  <!--
    Never scrolls sideways. Ten steps at full width is a scrollbar on a 1550-pixel screen,
    and a map you have to drag is not a map — so the labels go when the strip is tight and
    the numbered dots stay, which is what tells you where you are.
  -->
  <nav aria-label="Oda stüdyosu adımları" class="rc-card @container px-3 py-2">
    <ol class="flex items-center gap-0.5 text-xs">
      <li v-for="(step, at) in steps" :key="step.key" class="flex items-center">
        <component
          :is="choosable(step.key) ? 'button' : (selectable && own.includes(step.key) ? 'span' : NuxtLink)"
          :to="choosable(step.key) || (selectable && own.includes(step.key)) ? undefined : step.to"
          :type="choosable(step.key) ? 'button' : undefined"
          class="flex items-center gap-1.5 rounded-pill px-2.5 py-1.5 transition-colors"
          :class="{
            'bg-charcoal text-white': stateOf(step.key) === 'current',
            'text-ink hover:bg-bg-muted': stateOf(step.key) === 'done',
            'text-muted': stateOf(step.key) === 'ahead',
          }"
          :aria-current="stateOf(step.key) === 'current' ? 'step' : undefined"
          :aria-label="`${stateOf(step.key) === 'done' ? '✓' : at + 1} ${step.label}`"
          @click="choosable(step.key) && emit('select', step.key)"
        >
          <span
            class="grid size-5 place-items-center rounded-full border text-[10px] tabular-nums"
            :class="{
              'border-white/60': stateOf(step.key) === 'current',
              'border-accent-600 bg-accent-600 text-white': stateOf(step.key) === 'done',
              'border-line': stateOf(step.key) === 'ahead',
            }"
          >
            <template v-if="stateOf(step.key) === 'done'">✓</template>
            <template v-else>{{ at + 1 }}</template>
          </span>
          <!-- The current step keeps its name whatever the width; it is the one being read. -->
          <span :class="stateOf(step.key) === 'current' ? '' : 'hidden @[1020px]:inline'">{{ step.label }}</span>
        </component>

        <span v-if="at < steps.length - 1" class="mx-0.5 hidden h-px w-3 bg-line @[560px]:block" aria-hidden="true" />
      </li>

      <li v-if="next && next.key !== current && index(next.key) > index(current)" class="ml-auto hidden pl-4 text-muted @[1080px]:block">
        Sıradaki: <NuxtLink :to="next.to" class="text-ink underline-offset-4 hover:underline">{{ next.label }}</NuxtLink>
      </li>
    </ol>
  </nav>
</template>
