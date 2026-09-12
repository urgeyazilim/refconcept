<script setup lang="ts">
import type { Product, ProductMediaItem, ProductMediaView } from '@refconcept/ui/types'

/**
 * The product gallery.
 *
 * The first image is the cover, and that is stated rather than implied: it is what
 * appears in the catalogue grid, in search results and in the AI's design previews,
 * so a seller reordering images is making a merchandising decision, not tidying up.
 *
 * Reordering posts the whole list. The API rewrites every position in one
 * transaction because the database holds a "one cover per product" index that no
 * incremental swap can satisfy halfway through.
 */
const props = defineProps<{
  productId: string
  media: ProductMediaItem[]
  disabled?: boolean
}>()

const emit = defineEmits<{ updated: [Product] }>()

const api = useApi()

const uploading = ref(false)
const busyId = ref<string | null>(null)
const error = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

const MAX_IMAGES = 12

const canAddMore = computed(() => props.media.length < MAX_IMAGES && !props.disabled)

async function onFilesSelected(event: Event) {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])

  if (files.length === 0) return

  error.value = null
  uploading.value = true

  try {
    // Sequential rather than parallel: position is assigned server-side from the
    // current highest, so concurrent uploads would race for the same slot.
    for (const file of files) {
      if (props.media.length >= MAX_IMAGES) break

      const body = new FormData()
      body.append('file', file)

      const response = await api.request<{ data: Product }>(
        `/api/v1/seller/products/${props.productId}/media`,
        { method: 'POST', body },
      )

      emit('updated', response.data)
    }
  } catch (caught) {
    error.value = caught instanceof ApiError
      ? (caught.fieldError('file') ?? caught.message)
      : 'Görsel yüklenemedi.'
  } finally {
    uploading.value = false
    // Cleared so selecting the same file twice still fires a change event.
    input.value = ''
  }
}

async function move(item: ProductMediaItem, direction: -1 | 1) {
  const order = props.media.map(entry => entry.id)
  const index = order.indexOf(item.id)
  const target = index + direction

  if (index === -1 || target < 0 || target >= order.length) return

  const reordered = [...order]
  const [moved] = reordered.splice(index, 1)
  reordered.splice(target, 0, moved!)

  busyId.value = item.id
  error.value = null

  try {
    const response = await api.post<{ data: Product }>(
      `/api/v1/seller/products/${props.productId}/media/reorder`,
      { media: reordered },
    )

    emit('updated', response.data)
  } catch (caught) {
    error.value = caught instanceof ApiError ? caught.message : 'Sıralama güncellenemedi.'
  } finally {
    busyId.value = null
  }
}

async function makeCover(item: ProductMediaItem) {
  if (item.is_cover) return

  const reordered = [item.id, ...props.media.filter(entry => entry.id !== item.id).map(entry => entry.id)]

  busyId.value = item.id
  error.value = null

  try {
    const response = await api.post<{ data: Product }>(
      `/api/v1/seller/products/${props.productId}/media/reorder`,
      { media: reordered },
    )

    emit('updated', response.data)
  } catch (caught) {
    error.value = caught instanceof ApiError ? caught.message : 'Kapak görseli değiştirilemedi.'
  } finally {
    busyId.value = null
  }
}

async function saveAltText(item: ProductMediaItem, value: string) {
  if ((item.alt_text ?? '') === value) return

  busyId.value = item.id

  try {
    const response = await api.patch<{ data: Product }>(
      `/api/v1/seller/products/${props.productId}/media/${item.id}`,
      { alt_text: value === '' ? null : value },
    )

    emit('updated', response.data)
  } catch (caught) {
    error.value = caught instanceof ApiError ? caught.message : 'Görsel açıklaması kaydedilemedi.'
  } finally {
    busyId.value = null
  }
}

/**
 * Which side of the product this photograph shows.
 *
 * Ten seconds of a seller's time buys a better 3D model: told which picture is the back, the
 * generator stops inventing one, at no extra cost. Left blank, a vision model has a guess and
 * is allowed to say it does not know — so this is worth offering and never worth insisting on.
 *
 * One photograph per side. Picking a side another photograph already holds moves it there,
 * which the API does in one transaction: a seller correcting themselves means the new answer.
 */
const VIEW_OPTIONS: { value: ProductMediaView, label: string }[] = [
  { value: 'front', label: 'Önden' },
  { value: 'left', label: 'Soldan' },
  { value: 'back', label: 'Arkadan' },
  { value: 'right', label: 'Sağdan' },
]

async function saveView(item: ProductMediaItem, value: string) {
  const next = value === '' ? null : (value as ProductMediaView)

  if ((item.view ?? null) === next) return

  busyId.value = item.id
  error.value = null

  try {
    // Whoever held this side gives it up, which the API does in the same transaction — so
    // naming the front twice is a correction rather than an error.
    const response = await api.patch<{ data: Product }>(
      `/api/v1/seller/products/${props.productId}/media/${item.id}`,
      { view: next },
    )

    emit('updated', response.data)
  } catch (caught) {
    error.value = caught instanceof ApiError ? caught.message : 'Görsel yönü kaydedilemedi.'
  } finally {
    busyId.value = null
  }
}

async function remove(item: ProductMediaItem) {
  busyId.value = item.id
  error.value = null

  try {
    const response = await api.delete<{ data: Product }>(
      `/api/v1/seller/products/${props.productId}/media/${item.id}`,
    )

    emit('updated', response.data)
  } catch (caught) {
    error.value = caught instanceof ApiError ? caught.message : 'Görsel kaldırılamadı.'
  } finally {
    busyId.value = null
  }
}
</script>

<template>
  <section class="rc-card p-6 sm:p-8">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 class="text-lg font-medium">Görseller</h2>
        <p class="mt-1.5 max-w-[60ch] text-sm leading-relaxed text-ink-secondary">
          İlk görsel kapak görselidir: katalogda, arama sonuçlarında ve tasarım
          önerilerinde bu görsel kullanılır. JPEG, PNG veya WebP, en fazla 8 MB.
        </p>
        <p class="mt-1.5 max-w-[60ch] text-sm leading-relaxed text-ink-secondary">
          Görselin hangi yönden çekildiğini işaretlerseniz ürünün 3B modeli çok daha
          doğru çıkar: ön, sol, arka ve sağ görseli olan ürünün arka yüzü tahmin
          edilmez. İşaretlemek zorunlu değildir.
        </p>
      </div>

      <div class="text-right text-xs text-muted">{{ media.length }} / {{ MAX_IMAGES }}</div>
    </header>

    <RcAlert v-if="error" tone="danger" class="mt-5">{{ error }}</RcAlert>

    <div v-if="media.length > 0" class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <figure
        v-for="item in media"
        :key="item.id"
        class="overflow-hidden rounded-md border border-line bg-surface"
        :class="{ 'opacity-60': busyId === item.id }"
      >
        <div class="relative aspect-[4/3] bg-bg-muted">
          <img
            :src="item.url"
            :alt="item.alt_text ?? ''"
            class="size-full object-cover"
            loading="lazy"
          >
          <span
            v-if="item.is_cover"
            class="absolute left-2 top-2 rounded-pill bg-charcoal px-2.5 py-1 text-[11px] text-white"
          >
            Kapak
          </span>

          <span
            v-if="item.view"
            class="absolute right-2 top-2 rounded-pill bg-surface/90 px-2.5 py-1 text-[11px] text-ink-secondary"
          >
            {{ VIEW_OPTIONS.find(option => option.value === item.view)?.label }}
          </span>
        </div>

        <figcaption class="space-y-3 p-3">
          <input
            :value="item.alt_text ?? ''"
            type="text"
            placeholder="Görsel açıklaması (erişilebilirlik)"
            class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-xs"
            :disabled="disabled"
            @change="saveAltText(item, ($event.target as HTMLInputElement).value)"
          >

          <select
            :value="item.view ?? ''"
            aria-label="Görsel hangi yönden çekildi"
            class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-xs"
            :disabled="disabled || busyId !== null"
            @change="saveView(item, ($event.target as HTMLSelectElement).value)"
          >
            <option value="">Yön belirtilmedi</option>
            <option v-for="option in VIEW_OPTIONS" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>

          <div class="flex flex-wrap items-center gap-1.5">
            <button
              type="button"
              class="rounded-sm border border-line px-2.5 py-1.5 text-[11px] text-ink-secondary transition-colors hover:bg-bg-muted disabled:opacity-40"
              :disabled="disabled || item.is_cover || busyId !== null"
              @click="makeCover(item)"
            >
              Kapak yap
            </button>

            <button
              type="button"
              aria-label="Sola taşı"
              class="rounded-sm border border-line px-2.5 py-1.5 text-[11px] text-ink-secondary transition-colors hover:bg-bg-muted disabled:opacity-40"
              :disabled="disabled || item.position === 0 || busyId !== null"
              @click="move(item, -1)"
            >
              ←
            </button>

            <button
              type="button"
              aria-label="Sağa taşı"
              class="rounded-sm border border-line px-2.5 py-1.5 text-[11px] text-ink-secondary transition-colors hover:bg-bg-muted disabled:opacity-40"
              :disabled="disabled || item.position === media.length - 1 || busyId !== null"
              @click="move(item, 1)"
            >
              →
            </button>

            <button
              type="button"
              class="ml-auto rounded-sm px-2.5 py-1.5 text-[11px] text-danger transition-colors hover:bg-danger-subtle disabled:opacity-40"
              :disabled="disabled || busyId !== null"
              @click="remove(item)"
            >
              Kaldır
            </button>
          </div>
        </figcaption>
      </figure>
    </div>

    <div class="mt-6">
      <input
        ref="fileInput"
        type="file"
        accept="image/jpeg,image/png,image/webp"
        multiple
        class="sr-only"
        :disabled="!canAddMore || uploading"
        @change="onFilesSelected"
      >

      <button
        type="button"
        class="flex w-full items-center justify-center gap-2.5 rounded-md border border-dashed border-line-strong px-6 py-8 text-sm text-ink-secondary transition-colors hover:bg-bg-muted disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="!canAddMore || uploading"
        @click="fileInput?.click()"
      >
        <svg class="rc-icon size-5" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 5v14m-7-7h14" />
        </svg>
        <span v-if="uploading">Yükleniyor…</span>
        <span v-else-if="!canAddMore && !disabled">Görsel sınırına ulaşıldı</span>
        <span v-else-if="disabled">İnceleme sürerken görsel eklenemez</span>
        <span v-else>Görsel ekle</span>
      </button>
    </div>
  </section>
</template>
