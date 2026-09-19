<script setup lang="ts">
/**
 * Where the customer is, said in one line and one rule.
 *
 * It used to be ten numbered circles in a row — "1 Fotoğraf — 2 Eşyalar — 3 Oda" — and the
 * product owner's verdict was that a guided numbered wizard is amateur and the whole thing is
 * boring. They are right twice over: ten is too many things to hold at once, and Anthropic's
 * own design guidance says numbered markers read as a generated page unless the content
 * really is a sequence.
 *
 * Four phases really are a sequence, so the sequence is kept and the wizard is not. What is
 * shown is a rule in four parts, the name of the phase you are in, and the name of the one
 * after it, quietly. Progress is felt along the rule rather than counted in circles, and a
 * part you have already been through is a way back.
 *
 * The later things — arranging in 3D, the tour, the basket — are not phases. They are doors
 * you open from the design when you want them, which is the order the owner asked for:
 * photographs, the furniture out, what you want, the design, and then "change the layout" if
 * you do not like it.
 */
export type StudioStep = 'photo' | 'plate' | 'brief' | 'design'

const props = defineProps<{
  projectId: string
  roomId: string
  /** The phase this screen is about. */
  current: StudioStep
  /** Which phases are behind the customer, as far as this screen knows. */
  done: Partial<Record<StudioStep, boolean>>
  /** The design the last phase links to, when the screen knows one. */
  designId?: string | null
  /**
   * The phases this screen can open itself. With `selectable` these emit `select` instead of
   * navigating, so the room screen changes panel without a page load.
   */
  own?: StudioStep[]
  selectable?: boolean
}>()

const emit = defineEmits<{ (event: 'select', step: StudioStep): void }>()

const NuxtLink = resolveComponent('NuxtLink')

const room = computed(() => `/projects/${props.projectId}/rooms/${props.roomId}`)

/**
 * The four, in the product owner's own order and words.
 *
 * A room with no design yet sends its last phase back to the room, which is where a design is
 * asked for; there is nothing else it could mean.
 */
const phases = computed(() => [
  { key: 'photo' as const, label: 'Fotoğraf', to: `${room.value}#fotograf` },
  { key: 'plate' as const, label: 'Eşyalar', to: `${room.value}#esyalar` },
  { key: 'brief' as const, label: 'İstekler', to: `${room.value}#istekler` },
  {
    key: 'design' as const,
    label: 'Tasarım',
    to: props.designId ? `${room.value}/designs/${props.designId}#tasarim` : `${room.value}#istekler`,
  },
])

const at = computed(() => Math.max(0, phases.value.findIndex(phase => phase.key === props.current)))

/** The one after this, when there is one. Said quietly, so nobody has to guess what is coming. */
const next = computed(() => phases.value[at.value + 1] ?? null)

const own = computed<StudioStep[]>(() => props.own ?? ['photo', 'plate', 'brief'])

/**
 * A phase is a way back once it is behind you.
 *
 * Nothing ahead is clickable: a strip that lets somebody jump to the design before there is
 * one is a strip that hands out dead ends.
 */
function reachable(index: number): boolean {
  return index < at.value
}

/** Whether this screen answers the click itself rather than navigating to another one. */
function inside(key: StudioStep, index: number): boolean {
  return reachable(index) && props.selectable === true && own.value.includes(key)
}

function open(key: StudioStep, index: number): void {
  if (inside(key, index)) emit('select', key)
}
</script>

<template>
  <nav aria-label="Oda stüdyosu" class="flex min-w-0 flex-col gap-2 pt-1">
    <!--
      The rule. Four parts, filled behind you, lit where you are, a hairline ahead.

      Each part is its own element rather than one bar with a percentage, because each is a
      place you can go back to — and because a bar that fills smoothly says "loading", which
      is not what this is.
    -->
    <ol class="flex items-center gap-1.5">
      <li v-for="(phase, index) in phases" :key="phase.key" class="min-w-0 flex-1">
        <component
          :is="reachable(index) ? (inside(phase.key, index) ? 'button' : NuxtLink) : 'span'"
          :to="reachable(index) && !inside(phase.key, index) ? phase.to : undefined"
          :type="inside(phase.key, index) ? 'button' : undefined"
          class="block h-0.5 w-full rounded-pill transition-colors duration-500 ease-[cubic-bezier(0.4,0,0.2,1)]"
          :class="[
            index < at ? 'bg-charcoal/40 hover:bg-charcoal' : index === at ? 'bg-charcoal' : 'bg-line',
            reachable(index) ? 'cursor-pointer' : '',
          ]"
          :aria-current="index === at ? 'step' : undefined"
          :aria-label="`${phase.label}${index < at ? ' · geride' : index === at ? ' · buradasın' : ''}`"
          @click="open(phase.key, index)"
        />
      </li>
    </ol>

    <!--
      One name, and what is coming. Not four labels: three of them would be noise, and the
      rule already says how far along the room is.
    -->
    <p class="flex min-w-0 items-baseline gap-2 text-xs">
      <span class="truncate font-medium text-ink">{{ phases[at]?.label }}</span>
      <span v-if="next" class="truncate text-muted">sırada {{ next.label }}</span>
    </p>
  </nav>
</template>
