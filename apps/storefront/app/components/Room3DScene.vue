<script setup lang="ts">
/**
 * The customer's room, in three dimensions, at the size they confirmed.
 *
 * Deliberately thin. Everything that knows about Three.js lives in `app/room3d`, and this
 * component's whole job is to own a canvas, hand it to a {@link SceneManager} when it
 * mounts, and take it away again when it unmounts. A scene built inside a component's setup
 * is a scene rebuilt by every reactive change nobody expected to matter — the symptom is a
 * room that flickers and a laptop fan that will not stop.
 *
 * The view controls are the ones from the product storyboard, in the same words: Üstten,
 * Perspektif, İçeriden. They are three different questions rather than three angles — a
 * perspective view says how a room *feels* and is useless for judging whether a walkway is
 * wide enough, because the sofa nearest the camera looks bigger than the wardrobe at the
 * back. The plan has no perspective, so two gaps that measure the same look the same.
 */
import { SceneManager } from '~/room3d/SceneManager'
import type { RoomGeometry, RoomOpening, ViewMode } from '~/room3d/types'

const props = defineProps<{
  geometry: RoomGeometry
  openings: RoomOpening[]
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
const view = ref<ViewMode>('perspective')

/**
 * Held outside `ref` on purpose.
 *
 * A Three.js scene wrapped in a Vue proxy has every object in it made reactive, which is
 * thousands of getters on a hot path and a measurable frame cost for a graph that never
 * needs to be observed.
 */
let manager: SceneManager | null = null

const views: Array<{ value: ViewMode, label: string }> = [
  { value: 'top', label: 'Üstten' },
  { value: 'perspective', label: 'Perspektif' },
  { value: 'inside', label: 'İçeriden' },
]

onMounted(() => {
  if (canvas.value === null) {
    return
  }

  manager = new SceneManager(canvas.value)
  manager.setRoom(props.geometry, props.openings)
})

onBeforeUnmount(() => {
  manager?.dispose()
  manager = null
})

watch(view, mode => manager?.setView(mode))

// A corrected measurement is a different room, not a moved camera.
watch(
  () => [props.geometry, props.openings] as const,
  () => manager?.setRoom(props.geometry, props.openings),
  { deep: true },
)

/** The canvas as a PNG, for the render pipeline. */
defineExpose({ snapshot: () => manager?.snapshot() ?? null })
</script>

<template>
  <div class="relative aspect-[4/3] w-full overflow-hidden rounded-md bg-bg-muted">
    <!--
      `block` because a canvas is inline by default, which leaves a few pixels of line-height
      underneath it and a scrollbar that appears at some window sizes and not others.
    -->
    <canvas ref="canvas" class="block size-full" />

    <div class="absolute top-4 right-4 flex gap-1 rounded-pill bg-surface/90 p-1 backdrop-blur-sm">
      <button
        v-for="option in views"
        :key="option.value"
        type="button"
        class="rounded-pill px-3 py-1.5 text-xs transition-colors"
        :class="view === option.value ? 'bg-charcoal text-white' : 'text-ink-secondary hover:bg-bg-muted'"
        @click="view = option.value"
      >
        {{ option.label }}
      </button>
    </div>

    <p class="absolute right-4 bottom-4 left-4 text-right text-xs text-muted">
      {{ (geometry.width_mm / 1000).toFixed(2) }} × {{ (geometry.length_mm / 1000).toFixed(2) }} m ·
      tavan {{ (geometry.height_mm / 1000).toFixed(2) }} m
    </p>
  </div>
</template>
