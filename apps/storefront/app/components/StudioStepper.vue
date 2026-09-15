<script setup lang="ts">
/**
 * The studio's seven steps, and where the customer is in them.
 *
 * One strip at the top of every room screen — the room, the plan, the design — so
 * somebody always knows what they have done, what they are doing and what comes next. A
 * done step has a tick and stays a link; the current step is the one lit; the ones ahead
 * are visible and quiet. Nothing here is a button that does work: it is a map.
 *
 * The steps are the product contract's (docs/product/ODA_STUDYOSU_KURALLARI.md, §2).
 */
export type StudioStep = 'photo' | 'recognise' | 'confirm' | 'plate' | 'propose' | 'edit' | 'render'

const props = defineProps<{
  projectId: string
  roomId: string
  /** The step this screen is about. */
  current: StudioStep
  /** Which steps are done, as far as this screen knows. Unknown steps are shown quiet. */
  done: Partial<Record<StudioStep, boolean>>
}>()

const room = computed(() => `/projects/${props.projectId}/rooms/${props.roomId}`)

const steps = computed(() => [
  { key: 'photo' as const, label: 'Fotoğraf', to: `${room.value}#fotograf` },
  { key: 'recognise' as const, label: 'Tanıma', to: `${room.value}#olculer` },
  { key: 'confirm' as const, label: 'Onay', to: `${room.value}/plan` },
  { key: 'plate' as const, label: 'Boş oda', to: `${room.value}#fotograf` },
  { key: 'propose' as const, label: 'Öneri', to: `${room.value}#tasarim` },
  { key: 'edit' as const, label: 'Düzenle', to: `${room.value}/plan` },
  { key: 'render' as const, label: 'Render', to: `${room.value}#tasarim` },
])

const index = (key: StudioStep): number => steps.value.findIndex(step => step.key === key)

function stateOf(key: StudioStep): 'done' | 'current' | 'ahead' {
  if (key === props.current) {
    return 'current'
  }

  if (props.done[key] === true) {
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
  <nav aria-label="Oda stüdyosu adımları" class="rc-card overflow-x-auto px-4 py-3">
    <ol class="flex min-w-max items-center gap-1 text-xs">
      <li v-for="(step, at) in steps" :key="step.key" class="flex items-center">
        <NuxtLink
          :to="step.to"
          class="flex items-center gap-2 rounded-pill px-3 py-1.5 transition-colors"
          :class="{
            'bg-charcoal text-white': stateOf(step.key) === 'current',
            'text-ink hover:bg-bg-muted': stateOf(step.key) === 'done',
            'text-muted': stateOf(step.key) === 'ahead',
          }"
          :aria-current="stateOf(step.key) === 'current' ? 'step' : undefined"
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
          <span>{{ step.label }}</span>
        </NuxtLink>

        <span v-if="at < steps.length - 1" class="mx-1 h-px w-4 bg-line" aria-hidden="true" />
      </li>

      <li v-if="next && next.key !== current && index(next.key) > index(current)" class="ml-auto pl-4 text-muted">
        Sıradaki: <NuxtLink :to="next.to" class="text-ink underline-offset-4 hover:underline">{{ next.label }}</NuxtLink>
      </li>
    </ol>
  </nav>
</template>
