<script setup lang="ts">
import type { RoomMediaItem } from '@refconcept/ui/types'

/**
 * A room's photographs.
 *
 * Every thumbnail costs a request. The API never returns a URL in a listing — a link
 * is a separate, deliberate call that checks ownership and expires in five minutes —
 * so this component asks for one per photograph and holds it only in memory. That is
 * the price of a picture of somebody's living room not being one leaked log line away
 * from public, and it is worth paying.
 *
 * Links are refreshed rather than cached across a page load, because a stale one is
 * an image that silently fails to appear.
 */
const props = defineProps<{
  projectId: string
  roomId: string
  media: RoomMediaItem[]
  /** True while the room is being measured, so the button can say so. */
  scanning?: boolean
  canEdit: boolean
}>()

const emit = defineEmits<{ changed: [], scan: [] }>()

const api = useApi()

/** media id → signed URL, valid for about five minutes. */
const links = ref<Record<string, string>>({})

const uploading = ref(false)
const busyId = ref<string | null>(null)
const error = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

/**
 * A photograph taken with the phone upright.
 *
 * A render is a wide picture of a room, and a tall narrow photograph gives the model a strip
 * of it: two metres of ceiling and floor, and the walls the furniture has to go against cut
 * off at both sides. The reading copes; the design has less room to work in and it shows. The
 * product owner noticed it before we did, so the screen says it now rather than leaving
 * somebody to wonder why one room came out worse than another.
 */
function isPortrait(item: RoomMediaItem): boolean {
  return item.width !== null && item.height !== null && item.height > item.width * 1.1
}
const lightbox = ref<RoomMediaItem | null>(null)

const base = computed(() => `/api/v1/projects/${props.projectId}/rooms/${props.roomId}/media`)

async function loadLinks() {
  const next: Record<string, string> = {}

  await Promise.all(props.media.map(async (item) => {
    try {
      const response = await api.get<{ data: { url: string } }>(`${base.value}/${item.id}/link`)
      next[item.id] = response.data.url
    } catch {
      // One photograph failing to resolve should not blank the whole gallery.
    }
  }))

  links.value = next
}

watch(() => props.media.map(item => item.id).join(','), loadLinks, { immediate: true })

async function onFilesSelected(event: Event) {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])

  if (files.length === 0) return

  uploading.value = true
  error.value = null

  try {
    // Sequential: position is assigned server-side from the current highest, so
    // concurrent uploads would race for the same slot.
    for (const file of files) {
      const body = new FormData()
      body.append('file', file)

      await api.request(base.value, { method: 'POST', body })
    }

    emit('changed')
  } catch (caught) {
    error.value = caught instanceof ApiError
      ? (caught.fieldError('file') ?? caught.message)
      : 'Fotoğraf yüklenemedi.'
  } finally {
    uploading.value = false
    input.value = ''
  }
}

async function makePrimary(item: RoomMediaItem) {
  busyId.value = item.id
  error.value = null

  try {
    await api.patch(`${base.value}/${item.id}`, { set_primary: true })
    emit('changed')
  } catch (caught) {
    error.value = caught instanceof ApiError ? caught.message : 'Bu fotoğraf seçilemedi.'
  } finally {
    busyId.value = null
  }
}

async function remove(item: RoomMediaItem) {
  busyId.value = item.id
  error.value = null

  try {
    await api.delete(`${base.value}/${item.id}`)
    emit('changed')
  } catch (caught) {
    error.value = caught instanceof ApiError ? caught.message : 'Fotoğraf kaldırılamadı.'
  } finally {
    busyId.value = null
  }
}

const typeLabels: Record<string, string> = {
  photo: 'Fotoğraf',
  floor_plan: 'Kat planı',
  inspiration: 'İlham görseli',
  document: 'Belge',
  plate: 'Boş oda',
  scan: 'Oda taraması',
}

// --- the plate: the photograph with its furniture taken out --------------------------

/**
 * Plates are not gallery tiles. They belong to a photograph, and the gallery shows them
 * under it as before/after — the whole point of one is that everything but the furniture
 * is the same picture.
 */
/*
 * Nor is the scan. It is the room's measured shape, not a picture of it, and there is nothing
 * to show in a thumbnail; it belongs behind the "Tarama" button on the room itself.
 */
const tiles = computed(() => props.media.filter(item => item.type !== 'plate' && item.type !== 'scan'))

/*
 * Photographs, not everything the room holds.
 *
 * A plate is generated from a photograph and the measured scan is a file the room keeps;
 * neither is a picture the customer took. Counting them made a room with four pictures read
 * "5 / 20" and brought the limit on a picture early.
 */
const photoCount = computed(() => props.media.filter(item => item.type === 'photo').length)

const plateOf = (photo: RoomMediaItem): RoomMediaItem | undefined =>
  props.media.find(item => item.type === 'plate' && item.source_media_id === photo.id)

/** The photograph a design is made from — the one whose plate matters. */
const primary = computed(() => props.media.find(item => item.is_primary && item.type === 'photo') ?? tiles.value.find(item => item.type === 'photo'))

/**
 * Every photograph that has been emptied, the primary first.
 *
 * All of them, not only the primary: a customer who emptied the second corner and then made
 * another picture the primary one could not find the plate they had paid a minute for — it
 * existed and was shown nowhere.
 */
const emptied = computed(() =>
  tiles.value
    .filter(item => item.type === 'photo' && plateOf(item) !== undefined)
    .sort((a, b) => Number(b.id === primary.value?.id) - Number(a.id === primary.value?.id)),
)

const photoNumber = (item: RoomMediaItem): number => tiles.value.filter(tile => tile.type === 'photo').findIndex(tile => tile.id === item.id) + 1

/** Which photograph is being emptied right now, while the queue works on it. */
const clearing = ref<string | null>(null)
let clearingTimer: ReturnType<typeof setInterval> | null = null

async function clear(item: RoomMediaItem) {
  error.value = null
  clearing.value = item.id

  try {
    await api.post(`${base.value}/${item.id}/clear`)
  } catch (caught) {
    clearing.value = null
    error.value = caught instanceof ApiError ? caught.message : 'Oda boşaltılamadı.'

    return
  }

  // A minute of a model's time. The parent reloads the list until the plate appears.
  clearingTimer = setInterval(() => emit('changed'), 4_000)
}

watch(() => props.media, () => {
  if (clearing.value !== null && props.media.some(item => item.type === 'plate' && item.source_media_id === clearing.value)) {
    clearing.value = null

    if (clearingTimer !== null) {
      clearInterval(clearingTimer)
      clearingTimer = null
    }
  }
})

onBeforeUnmount(() => {
  if (clearingTimer !== null) {
    clearInterval(clearingTimer)
  }
})
</script>

<template>
  <!--
    Fills the step rather than sitting as a strip at the top of it.

    Step one used to end a third of the way down a screen that is supposed to be fixed and
    full, with the drop zone as a thin band and four hundred pixels of nothing under it.
  -->
  <section class="rc-card flex min-h-0 flex-1 flex-col p-6 sm:p-8">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 class="text-base font-medium">Fotoğraflar</h2>
        <!--
          Said because the screen was read the other way round.

          "Bunu kullan" beside three of four photographs looks like a choice of which one
          counts, and the product owner asked, fairly, why they had been told to photograph
          four corners at all. Every photograph is read; only the finished picture has to be
          drawn from one of them, because a render is one view of a room.
        -->
        <p class="mt-1 max-w-[62ch] text-xs leading-relaxed text-muted">
          Gündüz, birkaç köşeden, telefonu yan çevirerek. Hepsini birlikte okuyorum; tasarımın
          çizileceği kareyi de ben seçiyorum. Fotoğrafların yalnızca sana ait.
        </p>
      </div>

      <div class="flex items-center gap-3">
        <!--
          Measuring the room from every photograph at once.
          
          Offered rather than done: it costs money per room and it is not yet good enough to
          spend somebody's money on unasked. Two photographs is the floor; six taken from
          different corners is where it starts being worth the money.
        -->
        <RcButton
          v-if="canEdit && photoCount >= 2"
          size="sm"
          variant="secondary"
          :loading="scanning"
          :disabled="scanning"
          @click="emit('scan')"
        >
          Odayı ölç
        </RcButton>

        <span class="text-xs text-muted">{{ photoCount }} / 20</span>
      </div>
    </header>

    <RcAlert v-if="error" tone="danger" class="mt-5">{{ error }}</RcAlert>

    <!-- Small enough that the four corners of a room and their plates sit on one screen. -->
    <div v-if="tiles.length > 0" class="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5">
      <figure
        v-for="item in tiles"
        :key="item.id"
        class="overflow-hidden rounded-md border border-line bg-surface"
        :class="{ 'opacity-60': busyId === item.id }"
      >
        <button
          type="button"
          class="relative block aspect-[4/3] w-full bg-bg-muted"
          :aria-label="`${item.original_name} — büyüt`"
          @click="lightbox = item"
        >
          <img
            v-if="links[item.id]"
            :src="links[item.id]"
            :alt="item.caption ?? item.original_name"
            class="size-full object-cover"
          >
          <span v-else class="grid size-full place-items-center text-xs text-muted">Yükleniyor…</span>

          <span
            v-if="item.is_primary"
            class="absolute left-2 top-2 rounded-pill bg-charcoal px-2.5 py-1 text-[11px] text-white"
          >
            Tasarımı bundan çiziyorum
          </span>

          <span
            v-else-if="item.type !== 'photo'"
            class="absolute left-2 top-2 rounded-pill bg-bg-muted px-2.5 py-1 text-[11px] text-ink-secondary"
          >
            {{ typeLabels[item.type] }}
          </span>
        </button>

        <p v-if="isPortrait(item)" class="px-3 pt-2 text-[11px] leading-relaxed text-warning">
          Dikey kare. Telefonu yan çevirip çekersen odanın tamamı girer ve tasarım daha iyi
          çıkar.
        </p>

        <figcaption v-if="canEdit" class="flex flex-wrap items-center gap-1.5 p-3">
          <button
            v-if="!item.is_primary && item.type === 'photo'"
            type="button"
            class="rounded-sm border border-line px-2.5 py-1.5 text-[11px] text-ink-secondary transition-colors hover:bg-bg-muted disabled:opacity-40"
            :disabled="busyId !== null"
            @click="makePrimary(item)"
          >
            Bundan çiz
          </button>

          <!--
            Every photograph can be emptied, not only the primary one: a customer who shot
            four corners wants to see all four without the furniture. The render still
            starts from the primary photograph's plate, and the card below says so.
          -->
          <button
            v-if="item.type === 'photo' && !plateOf(item)"
            type="button"
            class="rounded-sm border border-line px-2.5 py-1.5 text-[11px] text-ink-secondary transition-colors hover:bg-bg-muted disabled:opacity-40"
            :disabled="clearing !== null"
            @click="clear(item)"
          >
            {{ clearing === item.id ? 'Boşaltılıyor…' : 'Eşyaları kaldır' }}
          </button>
          <span v-else-if="item.type === 'photo'" class="rounded-sm bg-bg-muted px-2.5 py-1.5 text-[11px] text-ink-secondary">
            Boş oda ✓
          </span>

          <button
            type="button"
            class="ml-auto rounded-sm px-2.5 py-1.5 text-[11px] text-danger transition-colors hover:bg-danger-subtle disabled:opacity-40"
            :disabled="busyId !== null"
            @click="remove(item)"
          >
            Sil
          </button>
        </figcaption>
      </figure>
    </div>

    <!--
      The emptied room.

      Every render starts from the plate: the customer's own walls, floor, windows and
      doors with nothing standing in front of them. Made once per photograph, in the
      background, at no charge; shown as before/after because the point is what stayed.
    -->
    <!--
      Only once there is an emptied photograph to show, or one on the way. The card used to
      open with a heading, three lines of explanation and a button of its own — the guide
      asks "Eşyaları kaldırayım mı?" on its own step, and the paragraph was a second voice
      saying the same thing under the photographs.
    -->
    <div v-if="primary && (emptied.length > 0 || clearing !== null)" class="mt-6 rounded-md border border-line p-4">
      <h3 class="text-sm font-medium">Boş oda</h3>

      <!--
        The primary photograph has no plate but another one does: said plainly, with the two
        ways out, because a render made from the furnished photograph when an emptied one is
        sitting right there is the kind of surprise nobody forgives.
      -->
      <RcAlert v-if="!plateOf(primary) && emptied.length > 0" tone="warning" class="mt-4">
        Ana fotoğrafın boş hâli henüz yok; render dolu fotoğraftan yapılır. Ana fotoğrafın
        eşyalarını kaldırın ya da boşaltılmış fotoğrafı "Bundan çiz" ile seçin.
      </RcAlert>

      <p v-if="clearing !== null" class="mt-3 text-xs text-muted">
        Yaklaşık bir-iki dakika sürer; bu sırada sayfada kalabilirsiniz.
      </p>

      <div v-for="photo in emptied" :key="photo.id" class="mt-4">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
          <p class="text-xs text-ink-secondary">
            Fotoğraf {{ photoNumber(photo) }}
            <span v-if="photo.id === primary.id" class="ml-1 rounded-pill bg-charcoal px-2 py-0.5 text-[10px] text-white">Render bundan başlar</span>
          </p>
          <button
            v-if="canEdit"
            type="button"
            class="rounded-sm border border-line px-2.5 py-1 text-[11px] text-ink-secondary hover:bg-bg-muted disabled:opacity-40"
            :disabled="busyId !== null"
            @click="remove(plateOf(photo)!)"
          >
            Boş odayı kaldır
          </button>
        </div>

        <RoomPlateCompare
          v-if="links[photo.id] && links[plateOf(photo)!.id]"
          :before="links[photo.id]!"
          :after="links[plateOf(photo)!.id]!"
        />
      </div>
    </div>

    <div v-if="canEdit" class="mt-6 flex min-h-0 flex-1 flex-col">
      <input
        ref="fileInput"
        type="file"
        accept="image/jpeg,image/png,image/webp,image/heic,image/heif"
        multiple
        class="sr-only"
        :disabled="uploading || photoCount >= 20"
        @change="onFilesSelected"
      >

      <button
        type="button"
        class="flex w-full min-h-0 flex-1 items-center justify-center gap-2.5 rounded-md border border-dashed border-line-strong px-6 py-8 text-sm text-ink-secondary transition-colors hover:bg-bg-muted disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="uploading || photoCount >= 20"
        @click="fileInput?.click()"
      >
        <svg class="rc-icon size-5" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 5v14m-7-7h14" />
        </svg>
        <span v-if="uploading">Yükleniyor…</span>
        <span v-else-if="photoCount >= 20">Fotoğraf sınırına ulaşıldı</span>
        <span v-else>Fotoğraf ekle</span>
      </button>
    </div>

    <!-- Lightbox -->
    <div
      v-if="lightbox"
      class="fixed inset-0 z-50 flex items-center justify-center bg-charcoal/80 p-4"
      @click.self="lightbox = null"
    >
      <div class="max-h-full max-w-4xl overflow-auto">
        <img
          v-if="links[lightbox.id]"
          :src="links[lightbox.id]"
          :alt="lightbox.caption ?? lightbox.original_name"
          class="max-h-[80vh] rounded-md"
        >
        <button
          type="button"
          class="mt-4 rounded-sm bg-white px-4 py-2 text-sm"
          @click="lightbox = null"
        >
          Kapat
        </button>
      </div>
    </div>
  </section>
</template>
