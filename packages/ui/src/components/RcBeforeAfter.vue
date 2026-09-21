<script setup lang="ts">
/**
 * The customer's room, before and after, under one handle.
 *
 * Side by side was honest and small. Two pictures at half width each are two pictures you
 * compare by looking back and forth, and the thing worth seeing — that this is the *same*
 * room — is exactly what that arrangement makes hard: the eye has to hold one image while
 * it reads the other, and it cannot.
 *
 * One image with a wipe solves it. The walls and windows line up under the handle, so
 * dragging is the proof. It is also the interaction people already know from every
 * renovation photograph they have ever seen, which is worth more than novelty.
 *
 * Driven by pointer events rather than mouse and touch separately, and by the keyboard as
 * well: this is a slider, it is labelled as one, and the arrow keys move it. A comparison
 * only a mouse can perform is a comparison half the visitors cannot make.
 */
const props = withDefaults(
  defineProps<{
    beforeSrc: string
    afterSrc: string
    beforeLabel?: string
    afterLabel?: string
  }>(),
  { beforeLabel: 'İlk hâli', afterLabel: 'Son hâli' },
)

const emit = defineEmits<{ (event: 'expand', which: 'before' | 'after'): void }>()

/**
 * The render's own shape, once the browser knows it.
 *
 * The frame used to be a fixed 16:10 and both pictures were cropped to fill it. A render is
 * 3:2, so the top and bottom of the thing the customer paid for were cut off — and the
 * product owner said exactly that: they could not see it properly. The frame takes the
 * render's shape instead, so the render is shown whole.
 *
 * Nothing is cropped any more, either. Both pictures are contained rather than covered, so
 * a window that will not fit the frame letterboxes into the charcoal instead of losing its
 * top and bottom — and the frame already has the render's shape, so on the usual path there
 * is nothing to letterbox. Somebody who asked to see their room should see all of it.
 */
const shape = ref<number | null>(null)

function measure(event: Event) {
  const image = event.target as HTMLImageElement

  if (image.naturalWidth > 0 && image.naturalHeight > 0) {
    shape.value = image.naturalWidth / image.naturalHeight
  }
}

/** Where the wipe sits, as a percentage from the left. */
const position = ref(50)
const frame = ref<HTMLElement | null>(null)
const dragging = ref(false)

function moveTo(clientX: number) {
  const box = frame.value?.getBoundingClientRect()

  if (!box || box.width === 0) {
    return
  }

  position.value = Math.min(100, Math.max(0, ((clientX - box.left) / box.width) * 100))
}

function startDrag(event: PointerEvent) {
  /*
   * A press that lands on a label is not a drag.
   *
   * Capturing the pointer retargets everything that follows to the capturing element, so
   * the frame was swallowing the click meant for "Son hâli": the label looked like a button,
   * highlighted like a button, and behaved like the wipe. Checked on the way in rather than
   * fixed with a z-index, because the labels are genuinely above the surface and the problem
   * is the capture, not the stack.
   */
  if ((event.target as HTMLElement | null)?.closest('button') !== null) {
    return
  }

  dragging.value = true

  /*
   * Capture, so a fast drag that leaves the image still moves the handle. Without it the
   * wipe stops the moment the pointer crosses the edge, which feels like the control has
   * broken rather than like the drag has ended.
   */
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
  moveTo(event.clientX)
}

function onDrag(event: PointerEvent) {
  if (dragging.value) {
    moveTo(event.clientX)
  }
}

function endDrag() {
  dragging.value = false
}

function nudge(step: number) {
  position.value = Math.min(100, Math.max(0, position.value + step))
}
</script>

<template>
  <!--
    The picture at its own shape, as large as the screen allows, centred.

    Height first and width derived from it, rather than the other way round. A frame given
    the full width and then capped in height stops being the picture's shape, and everything
    inside it is either cropped or letterboxed — which is how the top and bottom of somebody's
    room went missing. Sized from the height the stage can spare, the frame *is* the picture's
    shape, so there is nothing to crop and nothing to letterbox. On a narrow screen the width
    binds instead and the height follows it down.

    Room left below for the two lines that say what is in the picture and what the check found.
  -->
  <!-- The card hugs the picture: a slab of charcoal either side of it is not a design decision. -->
  <div class="mx-auto w-fit overflow-hidden rounded-md bg-charcoal">
    <!--
      The width the stage can afford at this shape; the height follows from the ratio. On a
      narrow screen max-width binds first and the height comes down with it.
    -->
    <div
      ref="frame"
      class="relative mx-auto max-w-full touch-none select-none"
      :style="{
        aspectRatio: String(shape ?? 1.5),
        width: `calc((100vh - 23rem) * ${shape ?? 1.5})`,
      }"
      @pointerdown="startDrag"
      @pointermove="onDrag"
      @pointerup="endDrag"
      @pointercancel="endDrag"
    >
      <!-- The finished room, full width, with the original wiped over it. -->
      <img
        :src="afterSrc"
        :alt="afterLabel"
        class="absolute inset-0 size-full object-contain"
        draggable="false"
        @load="measure"
      >

      <!--
        `clip-path` rather than a width-cropped copy: the two images stay the same size and
        in the same place, so nothing shifts as the handle moves. A cropped copy scales its
        contents and the walls slide against each other, which is precisely the illusion
        this component exists to avoid.
      -->
      <img
        :src="beforeSrc"
        :alt="beforeLabel"
        class="absolute inset-0 size-full object-contain"
        :style="{ clipPath: `inset(0 ${100 - position}% 0 0)` }"
        draggable="false"
      >

      <!-- The handle. -->
      <div
        class="pointer-events-none absolute inset-y-0 w-px bg-white/90 shadow-[0_0_12px_rgba(0,0,0,0.4)]"
        :style="{ left: `${position}%` }"
      >
        <div
          class="absolute top-1/2 flex size-11 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-white/70 bg-white/15 backdrop-blur-md"
        >
          <svg class="size-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="m9 6-5 6 5 6M15 6l5 6-5 6" />
          </svg>
        </div>
      </div>

      <!--
        The real control, invisible over the handle.

        A slider input rather than key handlers on a div: it arrives in the accessibility
        tree as a slider with a value, which is what it is, and every assistive technology
        already knows what to do with one.
      -->
      <input
        :value="position"
        type="range"
        min="0"
        max="100"
        step="1"
        aria-label="Öncesi ve sonrası arasında geçiş"
        class="absolute inset-0 z-10 size-full cursor-ew-resize opacity-0"
        @input="position = Number(($event.target as HTMLInputElement).value)"
        @keydown.left.prevent="nudge(-2)"
        @keydown.right.prevent="nudge(2)"
      >

      <!-- Which side is which, and a way into each at full size. -->
      <button
        type="button"
        class="absolute top-4 left-4 z-20 rounded-pill bg-black/45 px-3 py-1.5 text-xs text-white backdrop-blur-sm transition-colors hover:bg-black/65"
        @click.stop="emit('expand', 'before')"
      >
        {{ beforeLabel }}
      </button>

      <button
        type="button"
        class="absolute top-4 right-4 z-20 rounded-pill bg-black/45 px-3 py-1.5 text-xs text-white backdrop-blur-sm transition-colors hover:bg-black/65"
        @click.stop="emit('expand', 'after')"
      >
        {{ afterLabel }}
      </button>

      <!--
        The way to see it properly, said out loud.

        The two labels have always opened the picture full screen and neither of them looks
        like it does: they read as captions, because that is what a pill in the corner of a
        photograph is. The product owner had the whole feature in front of them and asked why
        it was missing. A button that says what it does, with the icon everybody already
        knows, and it sits where nothing is happening.
      -->
      <button
        type="button"
        class="absolute right-4 bottom-4 z-20 flex items-center gap-1.5 rounded-pill bg-black/45 px-3 py-1.5 text-xs text-white backdrop-blur-sm transition-colors hover:bg-black/65"
        @click.stop="emit('expand', 'after')"
      >
        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7" />
        </svg>
        Tam ekran
      </button>
    </div>
  </div>
</template>
