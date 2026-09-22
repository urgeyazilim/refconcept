<script setup lang="ts">
/**
 * One tool in the room: a drawing, and the words for it when the pointer rests there.
 *
 * The toolbars were words — "Duvara hizala", "Kopyala", "Kilitle", "Yakınlaş", "Sil" — which
 * is legible and takes the width of a sentence each, so the bar ran most of the way across
 * the room somebody was trying to look at. Every 3D tool ever made uses icons for the same
 * reason, and every one of them is unreadable to a person who has not used it before.
 *
 * So both, at different moments: the drawing always, and the name the instant the pointer
 * stops on it — with the key that does the same thing, which is how anybody ever learns a
 * shortcut. Written out rather than left to the browser's own `title`, because that appears
 * after a second and a half, in the system font, wherever the system feels like putting it,
 * and on a touch screen it never appears at all.
 *
 * `aria-label` carries the name for a screen reader; the tooltip is hidden from it, or the
 * name is announced twice.
 */
import { ICONS, type IconName } from '~/room3d/icons'

withDefaults(defineProps<{
  icon: IconName
  /** What this does, in the customer's words: "Duvara hizala". */
  label: string
  /** The key that does the same, if there is one: "R", "Ctrl+D", "Delete". */
  keys?: string | null
  /** A second line, for anything the name alone leaves open. */
  hint?: string | null
  /** On, for a toggle that is currently on. */
  active?: boolean
  /** Danger, for the one button nobody wants to press by accident. */
  tone?: 'plain' | 'danger'
  disabled?: boolean
  /** Where the tooltip goes, so it never opens off the edge of the scene. */
  side?: 'top' | 'bottom' | 'right'
}>(), {
  keys: null,
  hint: null,
  active: false,
  tone: 'plain',
  disabled: false,
  side: 'top',
})
</script>

<template>
  <span class="group relative inline-flex">
    <button
      type="button"
      :disabled="disabled"
      :aria-label="label"
      :aria-pressed="active ? 'true' : undefined"
      class="inline-flex size-9 items-center justify-center rounded-pill transition-colors disabled:opacity-35"
      :class="[
        active ? 'bg-charcoal text-white' : tone === 'danger' ? 'text-danger-strong hover:bg-danger-subtle' : 'text-ink-secondary hover:bg-bg-muted',
        disabled ? 'cursor-not-allowed' : '',
      ]"
    >
      <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path :d="ICONS[icon]" />
      </svg>
    </button>

    <!--
      Hidden until the pointer rests, and never in the way of the press: `pointer-events-none`
      keeps a tooltip that has just appeared under the cursor from swallowing the click that
      was already on its way.
    -->
    <span
      v-if="!disabled"
      aria-hidden="true"
      class="pointer-events-none absolute z-20 hidden w-max max-w-[22ch] rounded-sm bg-charcoal px-2 py-1 text-[11px] leading-snug text-white shadow-md group-hover:block group-focus-within:block"
      :class="{
        'bottom-full left-1/2 mb-1.5 -translate-x-1/2': side === 'top',
        'top-full left-1/2 mt-1.5 -translate-x-1/2': side === 'bottom',
        'top-1/2 left-full ml-1.5 -translate-y-1/2': side === 'right',
      }"
    >
      {{ label }}
      <span v-if="keys" class="text-white/60">· {{ keys }}</span>
      <span v-if="hint" class="block text-white/60">{{ hint }}</span>
    </span>
  </span>
</template>
