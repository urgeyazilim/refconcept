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
  canEdit: boolean
}>()

const emit = defineEmits<{ changed: [] }>()

const api = useApi()

/** media id → signed URL, valid for about five minutes. */
const links = ref<Record<string, string>>({})

const uploading = ref(false)
const busyId = ref<string | null>(null)
const error = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
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
}
</script>

<template>
  <section class="rc-card p-6 sm:p-8">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 class="text-lg font-medium">Fotoğraflar</h2>
        <p class="mt-1.5 max-w-[60ch] text-sm leading-relaxed text-ink-secondary">
          Odanın tamamını gösteren, gündüz çekilmiş bir fotoğraf en iyi sonucu verir.
          Fotoğraflarınız yalnızca size aittir; kimseyle paylaşılmaz ve arama
          motorlarına açılmaz.
        </p>
      </div>

      <span class="text-xs text-muted">{{ media.length }} / 20</span>
    </header>

    <RcAlert v-if="error" tone="danger" class="mt-5">{{ error }}</RcAlert>

    <div v-if="media.length > 0" class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <figure
        v-for="item in media"
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
            Tasarım bu fotoğraftan
          </span>

          <span
            v-else-if="item.type !== 'photo'"
            class="absolute left-2 top-2 rounded-pill bg-bg-muted px-2.5 py-1 text-[11px] text-ink-secondary"
          >
            {{ typeLabels[item.type] }}
          </span>
        </button>

        <figcaption v-if="canEdit" class="flex flex-wrap items-center gap-1.5 p-3">
          <button
            v-if="!item.is_primary && item.type === 'photo'"
            type="button"
            class="rounded-sm border border-line px-2.5 py-1.5 text-[11px] text-ink-secondary transition-colors hover:bg-bg-muted disabled:opacity-40"
            :disabled="busyId !== null"
            @click="makePrimary(item)"
          >
            Bunu kullan
          </button>

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

    <div v-if="canEdit" class="mt-6">
      <input
        ref="fileInput"
        type="file"
        accept="image/jpeg,image/png,image/webp,image/heic,image/heif"
        multiple
        class="sr-only"
        :disabled="uploading || media.length >= 20"
        @change="onFilesSelected"
      >

      <button
        type="button"
        class="flex w-full items-center justify-center gap-2.5 rounded-md border border-dashed border-line-strong px-6 py-8 text-sm text-ink-secondary transition-colors hover:bg-bg-muted disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="uploading || media.length >= 20"
        @click="fileInput?.click()"
      >
        <svg class="rc-icon size-5" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 5v14m-7-7h14" />
        </svg>
        <span v-if="uploading">Yükleniyor…</span>
        <span v-else-if="media.length >= 20">Fotoğraf sınırına ulaşıldı</span>
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
