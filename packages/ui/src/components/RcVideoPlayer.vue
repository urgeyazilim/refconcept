<script setup lang="ts">
/**
 * Eight seconds of somebody's own living room, given a player worth watching it in.
 *
 * The browser's default controls would have worked and would have been the wrong answer.
 * They are a grey bar built for hour-long video: a scrubber sized for finding minute
 * fourteen, a volume slider for a film that has no sound, and no way at all to do the one
 * thing a customer actually wants here — stop on a frame and lean in to look at the fabric
 * on the sofa they are about to buy.
 *
 * So: a scrubber the width of the frame for eight seconds, five-second skips either way,
 * frame-by-frame stepping while paused, and a zoom that goes to four times with a drag to
 * pan. It loops by default, because a short tour of a room is a loop and asking somebody to
 * press play again every eight seconds is asking them to stop watching.
 *
 * Everything reachable by keyboard, and the controls stay visible while anything inside
 * them has focus — a control bar that fades out from under the key you are about to press
 * is worse than no control bar.
 */
const props = withDefaults(
  defineProps<{
    src: string
    /** The still the film was made from: shown before play, so the frame is never black. */
    poster?: string
    /** Offered as a download, when the customer is allowed to keep it. */
    downloadName?: string
  }>(),
  { poster: undefined, downloadName: 'oda-videosu.mp4' },
)

const video = ref<HTMLVideoElement | null>(null)
const frame = ref<HTMLElement | null>(null)

const playing = ref(false)
const ready = ref(false)
const started = ref(false)
const currentTime = ref(0)
const duration = ref(0)
const buffered = ref(0)
const muted = ref(true)
const looping = ref(true)
const rate = ref(1)
const fullscreen = ref(false)
const scrubbing = ref(false)

/** Idle hides the bar; anything the customer does brings it back for three seconds. */
const idle = ref(false)
let idleTimer: ReturnType<typeof setTimeout> | undefined

/*
 * Zoom is a transform on the video rather than a change of size.
 *
 * The frame keeps its aspect ratio and its place on the page, so zooming in does not push
 * the shopping list down the screen — and panning is a translate rather than a scroll,
 * which means it works identically under a mouse, a finger and a trackpad.
 */
const ZOOM_STEPS = [1, 1.5, 2, 3, 4] as const
const zoom = ref(1)
const pan = ref({ x: 0, y: 0 })
const panning = ref(false)
let panFrom = { x: 0, y: 0, panX: 0, panY: 0 }

const zoomed = computed(() => zoom.value > 1)

const progress = computed(() => (duration.value > 0 ? (currentTime.value / duration.value) * 100 : 0))
const bufferedPercent = computed(() => (duration.value > 0 ? (buffered.value / duration.value) * 100 : 0))

/** Controls stay up while paused, while scrubbing, and until the customer goes quiet. */
const controlsVisible = computed(() => !playing.value || scrubbing.value || !idle.value)

function format(seconds: number): string {
  if (!Number.isFinite(seconds)) {
    return '0:00'
  }

  const whole = Math.floor(seconds)

  // Tenths, because the whole film is eight seconds: a clock that only counts seconds
  // barely moves, and a scrubber whose readout barely moves feels broken.
  const tenths = Math.floor((seconds - whole) * 10)

  return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}.${tenths}`
}

function wake() {
  idle.value = false

  clearTimeout(idleTimer)
  idleTimer = setTimeout(() => {
    // Only hides while something is actually happening. A paused frame with no controls is
    // a picture the customer cannot get out of.
    if (playing.value && !scrubbing.value) {
      idle.value = true
    }
  }, 2600)
}

function toggle() {
  const element = video.value

  if (!element) {
    return
  }

  started.value = true

  if (element.paused) {
    void element.play()
  } else {
    element.pause()
  }

  wake()
}

function seekBy(seconds: number) {
  const element = video.value

  if (!element) {
    return
  }

  element.currentTime = Math.min(
    Math.max(0, element.currentTime + seconds),
    element.duration || 0,
  )

  wake()
}

/**
 * One frame at a time, while paused.
 *
 * The reason anybody pauses this video is to look at something, and the frame they want is
 * usually the one just before or just after the one they stopped on. A twenty-fifth of a
 * second is close enough to a frame at every rate these films are made at.
 */
function step(frames: number) {
  const element = video.value

  if (!element) {
    return
  }

  element.pause()
  element.currentTime = Math.min(Math.max(0, element.currentTime + frames * 0.04), element.duration || 0)
  wake()
}

function seekTo(event: Event) {
  const element = video.value
  const value = Number((event.target as HTMLInputElement).value)

  if (element && Number.isFinite(element.duration)) {
    element.currentTime = (value / 100) * element.duration
  }

  currentTime.value = (value / 100) * duration.value
  wake()
}

function cycleZoom() {
  const index = ZOOM_STEPS.indexOf(zoom.value as (typeof ZOOM_STEPS)[number])
  const next = ZOOM_STEPS[(index + 1) % ZOOM_STEPS.length] ?? 1

  zoom.value = next

  if (next === 1) {
    // Back to one means back to the middle. Leaving the pan where it was would show a
    // corner of the room with black beside it.
    pan.value = { x: 0, y: 0 }
  }

  wake()
}

function cycleRate() {
  // Slow is the useful direction here: a two-minute film has fast-forward, an eight-second
  // one has "let me actually see that".
  const rates = [1, 0.5, 0.25, 1.5]
  const next = rates[(rates.indexOf(rate.value) + 1) % rates.length] ?? 1

  rate.value = next

  if (video.value) {
    video.value.playbackRate = next
  }

  wake()
}

function toggleMute() {
  muted.value = !muted.value

  if (video.value) {
    video.value.muted = muted.value
  }

  wake()
}

function toggleLoop() {
  looping.value = !looping.value
  wake()
}

async function toggleFullscreen() {
  const element = frame.value

  if (!element) {
    return
  }

  if (document.fullscreenElement) {
    await document.exitFullscreen()
  } else {
    await element.requestFullscreen?.()
  }

  wake()
}

// --- panning while zoomed ---------------------------------------------------

function startPan(event: PointerEvent) {
  if (!zoomed.value) {
    return
  }

  panning.value = true
  panFrom = { x: event.clientX, y: event.clientY, panX: pan.value.x, panY: pan.value.y }
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
}

function movePan(event: PointerEvent) {
  if (!panning.value) {
    return
  }

  /*
   * Clamped to how far the scaled picture actually overhangs the frame, so the room can
   * never be dragged off the edge and leave the customer looking at nothing wondering
   * where their video went.
   */
  const box = frame.value?.getBoundingClientRect()
  const limitX = box ? (box.width * (zoom.value - 1)) / 2 : 0
  const limitY = box ? (box.height * (zoom.value - 1)) / 2 : 0

  pan.value = {
    x: Math.min(limitX, Math.max(-limitX, panFrom.panX + (event.clientX - panFrom.x))),
    y: Math.min(limitY, Math.max(-limitY, panFrom.panY + (event.clientY - panFrom.y))),
  }
}

function endPan() {
  panning.value = false
}

// --- keyboard ---------------------------------------------------------------

/**
 * The shortcuts everybody already knows, plus the two this film needs.
 *
 * Bound on the frame rather than the document: a page with a video on it is still a page,
 * and stealing the space bar from somebody scrolling the shopping list underneath would be
 * a player that had decided it was the most important thing on screen.
 */
function onKey(event: KeyboardEvent) {
  const handlers: Record<string, () => void> = {
    ' ': toggle,
    k: toggle,
    ArrowRight: () => (event.shiftKey ? step(1) : seekBy(1)),
    ArrowLeft: () => (event.shiftKey ? step(-1) : seekBy(-1)),
    l: () => seekBy(5),
    j: () => seekBy(-5),
    m: toggleMute,
    f: () => void toggleFullscreen(),
    z: cycleZoom,
    Home: () => seekBy(-Number.MAX_SAFE_INTEGER),
  }

  const handler = handlers[event.key]

  if (handler) {
    event.preventDefault()
    handler()
  }
}

// --- wiring -----------------------------------------------------------------

function onLoaded() {
  const element = video.value

  if (!element) {
    return
  }

  duration.value = element.duration
  ready.value = true
}

function onTimeUpdate() {
  const element = video.value

  if (!element) {
    return
  }

  currentTime.value = element.currentTime

  const ranges = element.buffered

  buffered.value = ranges.length > 0 ? ranges.end(ranges.length - 1) : 0
}

function onFullscreenChange() {
  fullscreen.value = document.fullscreenElement !== null
}

onMounted(() => {
  document.addEventListener('fullscreenchange', onFullscreenChange)
})

onBeforeUnmount(() => {
  document.removeEventListener('fullscreenchange', onFullscreenChange)
  clearTimeout(idleTimer)
})

// A new film in the same player starts from the beginning, unzoomed and unplayed.
watch(() => props.src, () => {
  started.value = false
  playing.value = false
  currentTime.value = 0
  zoom.value = 1
  pan.value = { x: 0, y: 0 }
})
</script>

<template>
  <div
    ref="frame"
    class="group/player relative aspect-[16/9] w-full overflow-hidden rounded-md bg-black select-none"
    tabindex="0"
    role="region"
    aria-label="Oda videosu oynatıcı"
    @keydown="onKey"
    @pointermove="wake"
    @pointerleave="playing && (idle = true)"
  >
    <!--
      The picture itself.

      `object-contain` rather than cover: a film of somebody's room must not be cropped to
      fit a box, and the letterboxing is black on black so nobody sees a border.
    -->
    <video
      ref="video"
      :src="src"
      :poster="poster"
      :loop="looping"
      :muted="muted"
      playsinline
      preload="metadata"
      class="size-full object-contain transition-transform duration-200 ease-out"
      :class="[
        zoomed ? (panning ? 'cursor-grabbing' : 'cursor-grab') : 'cursor-pointer',
        panning ? 'transition-none' : '',
      ]"
      :style="{ transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})` }"
      @loadedmetadata="onLoaded"
      @timeupdate="onTimeUpdate"
      @play="playing = true; wake()"
      @pause="playing = false; idle = false"
      @ended="playing = false; idle = false"
      @click="zoomed ? undefined : toggle()"
      @pointerdown="startPan"
      @pointermove="movePan"
      @pointerup="endPan"
      @pointercancel="endPan"
    />

    <!--
      The way in.

      One large target over the whole frame until the film has been started once, because
      "press play" should not require finding a twenty-pixel triangle in a corner.
    -->
    <button
      v-if="!started"
      type="button"
      class="absolute inset-0 z-20 flex items-center justify-center bg-gradient-to-t from-black/50 via-black/10 to-black/30 transition-colors hover:from-black/60"
      aria-label="Videoyu oynat"
      @click="toggle"
    >
      <span
        class="flex size-20 items-center justify-center rounded-full border border-white/60 bg-white/15 backdrop-blur-md transition-transform duration-200 group-hover/player:scale-105"
      >
        <svg class="ml-1 size-8 text-white" viewBox="0 0 24 24" fill="currentColor">
          <path d="M8 5.5v13l11-6.5z" />
        </svg>
      </span>
    </button>

    <!-- Zoom, said out loud, so nobody wonders why dragging moves the picture. -->
    <div
      v-if="zoomed"
      class="pointer-events-none absolute top-4 left-4 z-20 rounded-pill bg-black/50 px-3 py-1.5 text-xs text-white backdrop-blur-sm"
    >
      {{ zoom }}× · sürükleyerek gezin
    </div>

    <!--
      The controls.

      One bar, over a gradient rather than a solid block, so the bottom of the room stays
      visible behind them. Hidden by translation rather than by `v-if`: a bar that is
      removed from the document takes the focus ring with it.
    -->
    <div
      class="absolute inset-x-0 bottom-0 z-30 bg-gradient-to-t from-black/85 via-black/45 to-transparent px-3 pt-10 pb-3 transition-all duration-200 sm:px-4 sm:pb-4"
      :class="controlsVisible
        ? 'translate-y-0 opacity-100'
        : 'pointer-events-none translate-y-3 opacity-0'"
      @pointerdown.stop
    >
      <!-- The scrubber. Full width, because eight seconds deserve the whole frame. -->
      <div class="relative h-6">
        <div class="pointer-events-none absolute inset-x-0 top-1/2 h-1 -translate-y-1/2 overflow-hidden rounded-pill bg-white/25">
          <div class="h-full bg-white/30" :style="{ width: `${bufferedPercent}%` }" />
          <div
            class="absolute inset-y-0 left-0 rounded-pill bg-white"
            :style="{ width: `${progress}%` }"
          />
        </div>

        <div
          class="pointer-events-none absolute top-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white shadow-[0_0_10px_rgba(0,0,0,0.5)] transition-transform duration-150"
          :class="scrubbing ? 'scale-125' : 'scale-100 group-hover/player:scale-110'"
          :style="{ left: `${progress}%` }"
        />

        <!--
          A real slider under the drawn one. It arrives in the accessibility tree as a
          slider with a value, which is what it is, and every assistive technology already
          knows what to do with one.
        -->
        <input
          :value="progress"
          type="range"
          min="0"
          max="100"
          step="0.1"
          aria-label="Video konumu"
          class="absolute inset-0 size-full cursor-pointer opacity-0"
          @input="seekTo"
          @pointerdown="scrubbing = true"
          @pointerup="scrubbing = false"
          @blur="scrubbing = false"
        >
      </div>

      <div class="mt-1 flex items-center gap-1 text-white sm:gap-1.5">
        <button type="button" class="rc-vp-btn" :aria-label="playing ? 'Duraklat' : 'Oynat'" @click="toggle">
          <svg v-if="playing" class="size-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z" />
          </svg>
          <svg v-else class="ml-0.5 size-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M8 5.5v13l11-6.5z" />
          </svg>
        </button>

        <button type="button" class="rc-vp-btn" aria-label="5 saniye geri" @click="seekBy(-5)">
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M11 8H6.5A2.5 2.5 0 0 0 4 10.5v0A2.5 2.5 0 0 0 6.5 13H8" />
            <path d="m8 5-3 3 3 3" />
            <path d="M13.5 19V9l-2 1.4" />
            <path d="M17 9h3.5m-3.5 0v4h2.2a1.8 1.8 0 0 1 0 3.6H17" />
          </svg>
        </button>

        <button type="button" class="rc-vp-btn" aria-label="5 saniye ileri" @click="seekBy(5)">
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M13 8h4.5A2.5 2.5 0 0 1 20 10.5v0A2.5 2.5 0 0 1 17.5 13H16" />
            <path d="m16 5 3 3-3 3" />
            <path d="M6.5 19V9l-2 1.4" />
            <path d="M10 9h3.5m-3.5 0v4h2.2a1.8 1.8 0 0 1 0 3.6H10" />
          </svg>
        </button>

        <!--
          Frame stepping, and only while paused: it is the control for studying a still, and
          offering it during playback would be offering a button that fights the film.
        -->
        <template v-if="!playing && started">
          <button type="button" class="rc-vp-btn hidden sm:inline-flex" aria-label="Bir kare geri" @click="step(-1)">
            <svg class="size-5" viewBox="0 0 24 24" fill="currentColor">
              <path d="M16 5.5v13L7 12zM6 5h1.6v14H6z" />
            </svg>
          </button>

          <button type="button" class="rc-vp-btn hidden sm:inline-flex" aria-label="Bir kare ileri" @click="step(1)">
            <svg class="size-5" viewBox="0 0 24 24" fill="currentColor">
              <path d="M8 5.5v13L17 12zM16.4 5H18v14h-1.6z" />
            </svg>
          </button>
        </template>

        <span class="ml-1.5 font-mono text-xs tabular-nums text-white/85">
          {{ format(currentTime) }} / {{ format(duration) }}
        </span>

        <span class="flex-1" />

        <button
          type="button"
          class="rc-vp-btn w-auto px-2 font-mono text-xs"
          :class="rate === 1 ? '' : 'bg-white/20'"
          aria-label="Oynatma hızı"
          @click="cycleRate"
        >
          {{ rate }}×
        </button>

        <button
          type="button"
          class="rc-vp-btn"
          :class="zoomed ? 'bg-white/20' : ''"
          :aria-label="`Yakınlaştır (şu an ${zoom}×)`"
          @click="cycleZoom"
        >
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="6.5" />
            <path d="m16 16 4.5 4.5" />
            <path v-if="!zoomed" d="M11 8.5v5M8.5 11h5" />
            <path v-else d="M8.5 11h5" />
          </svg>
        </button>

        <button
          type="button"
          class="rc-vp-btn hidden sm:inline-flex"
          :class="looping ? 'bg-white/20' : ''"
          :aria-label="looping ? 'Tekrarı kapat' : 'Tekrarla'"
          @click="toggleLoop"
        >
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 9a4 4 0 0 1 4-4h8l-2.5-2.5M20 15a4 4 0 0 1-4 4H8l2.5 2.5" />
          </svg>
        </button>

        <button type="button" class="rc-vp-btn" :aria-label="muted ? 'Sesi aç' : 'Sesi kapat'" @click="toggleMute">
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 9.5h3L12 6v12l-4-3.5H5z" />
            <path v-if="muted" d="m16.5 9.5 4 5m0-5-4 5" />
            <path v-else d="M15.5 9.5a3.5 3.5 0 0 1 0 5M18 7.5a7 7 0 0 1 0 9" />
          </svg>
        </button>

        <!--
          A copy of their own room to keep. A plain link with `download`, because the file is
          behind a short-lived signed URL and a script-driven save would have to hold it.
        -->
        <a
          :href="src"
          :download="downloadName"
          class="rc-vp-btn"
          aria-label="Videoyu indir"
          @click.stop
        >
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 4v10m0 0 4-4m-4 4-4-4M5 18.5h14" />
          </svg>
        </a>

        <button
          type="button"
          class="rc-vp-btn"
          :aria-label="fullscreen ? 'Tam ekrandan çık' : 'Tam ekran'"
          @click="toggleFullscreen"
        >
          <svg v-if="fullscreen" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5" />
          </svg>
          <svg v-else class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Still fetching the first frame. -->
    <div
      v-if="!ready"
      class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center bg-black/40"
    >
      <span class="size-8 animate-spin rounded-full border-2 border-white/25 border-t-white/90" />
    </div>
  </div>
</template>

<style scoped>
/*
 * One class for eleven identical buttons.
 *
 * Written here rather than repeated in every `class` attribute because it is genuinely the
 * same control eleven times, and a row of buttons where the tenth is two pixels different
 * from the other nine is the sort of thing nobody can name and everybody notices.
 */
.rc-vp-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 2.25rem;
  width: 2.25rem;
  flex-shrink: 0;
  border-radius: 9999px;
  color: white;
  transition: background-color 150ms ease, transform 150ms ease;
}

.rc-vp-btn:hover {
  background-color: rgb(255 255 255 / 0.18);
}

.rc-vp-btn:active {
  transform: scale(0.94);
}

.rc-vp-btn:focus-visible {
  outline: 2px solid rgb(255 255 255 / 0.9);
  outline-offset: 2px;
}

/* The frame itself takes focus for the keyboard shortcuts; it should say so, quietly. */
.group\/player:focus-visible {
  outline: 2px solid rgb(255 255 255 / 0.55);
  outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
  .rc-vp-btn,
  video {
    transition: none;
  }
}
</style>
