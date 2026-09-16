<script setup lang="ts">
/**
 * The guide: the one card that speaks to the customer, on every studio screen.
 *
 * Not a wizard and not an alert. It says what is happening in the first person, in one or
 * two sentences, and puts the single next step beside them — the way somebody standing next
 * to you in your own living room would. The words live in docs/product/REHBER.md; this
 * component only gives them a voice: an icon, a line, a button, and the small tips that
 * belong to the moment (the four angles to photograph from, the things it saw and would
 * take out).
 *
 * The parent decides what the guide says. The guide never fetches anything, so the same
 * card can stand on the projects list, a project and a room without three copies of the
 * logic that knows what a room needs next.
 */
export type GuideIcon = 'sparkle' | 'camera' | 'eye' | 'broom' | 'ruler' | 'pencil' | 'light' | 'check' | 'home' | 'door'

export interface GuideTip {
  icon: GuideIcon
  label: string
  hint?: string
}

export interface GuideChoice {
  key: string
  label: string
  selected: boolean
}

const props = withDefaults(defineProps<{
  icon: GuideIcon
  /** What the guide says, one sentence. */
  say: string
  /** The rest of it, optional. */
  detail?: string | null
  /** The single next step. */
  action?: { label: string, to?: string, busy?: boolean, disabled?: boolean, note?: string } | null
  /** A quieter second way. */
  secondary?: { label: string, to?: string } | null
  /** Small illustrated tips for this moment (photograph angles, and the like). */
  tips?: GuideTip[]
  /** Things to pick from — what stays, what goes. */
  choices?: GuideChoice[]
  /** The guide is working on something. */
  busy?: boolean
}>(), {
  detail: null,
  action: null,
  secondary: null,
  tips: () => [],
  choices: () => [],
  busy: false,
})

const emit = defineEmits<{
  (event: 'act' | 'secondary'): void
  (event: 'toggle', key: string): void
}>()

const paths: Record<GuideIcon, string> = {
  sparkle: 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 16l.9 2.1L22 19l-2.1.9L19 22l-.9-2.1L16 19l2.1-.9zM5 15l.7 1.6L7.3 17l-1.6.7L5 19.3l-.7-1.6L2.7 17l1.6-.4z',
  camera: 'M4 8h3l2-3h6l2 3h3v11H4zM12 17a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  broom: 'M14 3l7 7M11 6l7 7M16 11l-8 8a3 3 0 0 1-4-4l8-8M4 20l4-1',
  ruler: 'M3 17l14-14 4 4L7 21zM8 12l2 2M11 9l2 2M14 6l2 2',
  pencil: 'M4 20l4-1L19 8l-3-3L5 16zM14 7l3 3',
  light: 'M9 18h6M10 21h4M12 3a6 6 0 0 0-4 10.5c.6.6 1 1.4 1 2.5h6c0-1.1.4-1.9 1-2.5A6 6 0 0 0 12 3z',
  check: 'M4 12l5 5L20 6',
  home: 'M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z',
  door: 'M6 3h12v18H6zM14 12h1M6 21h12',
}

// A new sentence slides in; the old one is not repainted in place, which reads as a glitch.
const key = computed(() => `${props.icon}|${props.say}`)
</script>

<template>
  <!--
    One band across the top, not a card down the side.

    The guide used to stand in a 380-pixel column beside every screen: a tall card with a
    portrait avatar, four lines of text and two buttons, on the room, the plan and the
    design. The product owner's verdict was that no other site does this, and they were
    right — the content is what somebody came for, and it was getting two thirds of the
    width. So the guide is a strip: who is talking, what it says, what to press. The screen
    below it belongs to the room.
  -->
  <section
    class="rc-card flex flex-wrap items-center gap-x-5 gap-y-3 px-4 py-3 sm:px-5"
    aria-live="polite"
  >
    <span
      class="grid size-9 shrink-0 place-items-center rounded-full bg-charcoal text-white"
      :class="{ 'animate-pulse': busy }"
      title="Yapay zekâ rehberin"
      aria-label="Yapay zekâ rehberin"
    >
      <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path :d="paths[icon]" />
      </svg>
    </span>

    <Transition name="guide" mode="out-in">
      <div :key="key" class="min-w-0 flex-1">
        <p class="text-[15px] leading-snug font-medium text-ink">{{ say }}</p>
        <p v-if="detail" class="mt-0.5 max-w-[92ch] text-[13px] leading-snug text-ink-secondary">{{ detail }}</p>
      </div>
    </Transition>

    <!-- What to press, at the end of the sentence, where the eye already is. -->
    <div v-if="action || secondary" class="ml-auto flex shrink-0 flex-wrap items-center gap-3">
      <NuxtLink v-if="secondary?.to" :to="secondary.to" class="text-sm whitespace-nowrap text-ink-secondary underline-offset-4 hover:underline">
        {{ secondary.label }}
      </NuxtLink>
      <button
        v-else-if="secondary"
        type="button"
        class="text-sm whitespace-nowrap text-ink-secondary underline-offset-4 hover:underline"
        @click="emit('secondary')"
      >
        {{ secondary.label }}
      </button>

      <template v-if="action">
        <span v-if="action.note" class="hidden text-xs text-muted lg:inline">{{ action.note }}</span>
        <NuxtLink
          v-if="action.to"
          :to="action.to"
          class="inline-flex items-center gap-2 rounded-pill bg-charcoal px-4 py-2 text-sm whitespace-nowrap text-white transition-transform hover:-translate-y-px"
        >
          {{ action.label }}
          <span aria-hidden="true">→</span>
        </NuxtLink>
        <button
          v-else
          type="button"
          class="inline-flex items-center gap-2 rounded-pill bg-charcoal px-4 py-2 text-sm whitespace-nowrap text-white transition-transform hover:-translate-y-px disabled:opacity-50"
          :disabled="action.disabled || action.busy"
          @click="emit('act')"
        >
          <span v-if="action.busy" class="size-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white" aria-hidden="true" />
          {{ action.label }}
          <span v-if="!action.busy" aria-hidden="true">→</span>
        </button>
      </template>
    </div>

    <!--
      The tips and the choices belong to their own moment, so they take their own line
      rather than squeezing the sentence: four photographing angles, or the things the
      reading saw and would take out.
    -->
    <ul v-if="tips.length > 0" class="flex basis-full flex-wrap items-center gap-2 border-t border-line/70 pt-3">
      <li v-for="tip in tips" :key="tip.label" class="inline-flex items-center gap-1.5 rounded-pill bg-bg-muted px-2.5 py-1 text-xs">
        <svg class="size-3.5 shrink-0 text-accent-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path :d="paths[tip.icon]" />
        </svg>
        <span class="font-medium text-ink">{{ tip.label }}</span>
        <span v-if="tip.hint" class="text-muted">{{ tip.hint }}</span>
      </li>
    </ul>

    <ul v-if="choices.length > 0" class="flex basis-full flex-wrap gap-2 border-t border-line/70 pt-3" aria-label="Kaldırılacaklar ve kalacaklar">
      <li v-for="choice in choices" :key="choice.key">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-pill border px-3 py-1 text-xs transition-colors"
          :class="choice.selected
            ? 'border-charcoal bg-charcoal text-white'
            : 'border-line bg-surface text-ink-secondary line-through decoration-line/80 hover:bg-bg-muted'"
          :aria-pressed="choice.selected"
          @click="emit('toggle', choice.key)"
        >
          <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path :d="choice.selected ? paths.broom : paths.check" />
          </svg>
          {{ choice.label }}
          <span class="text-[10px] opacity-70">{{ choice.selected ? 'kaldır' : 'kalsın' }}</span>
        </button>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.guide-enter-active,
.guide-leave-active {
  transition: opacity 180ms ease, transform 180ms ease;
}

.guide-enter-from {
  opacity: 0;
  transform: translateY(6px);
}

.guide-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
