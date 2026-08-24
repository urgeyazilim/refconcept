<script setup lang="ts">
import type { Paginated, Product } from '@refconcept/ui/types'

/**
 * The seller's listings.
 *
 * Grouped by what the seller can do about them rather than by creation date: a
 * rejected listing needs attention today, a published one does not. The counts in
 * the filter row come from the same request as the table, so they can never disagree
 * with what is on screen.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Ürünlerim' })

const api = useApi()

const products = ref<Product[]>([])
const total = ref(0)
const loading = ref(true)
const loadError = ref<string | null>(null)
const search = ref('')
const moderationFilter = ref('')

const filters = [
  { value: '', label: 'Tümü' },
  { value: 'draft', label: 'Taslak' },
  { value: 'pending_review', label: 'İncelemede' },
  { value: 'approved', label: 'Yayında' },
  { value: 'rejected', label: 'Reddedildi' },
]

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const query: Record<string, string> = {}
    if (moderationFilter.value) query.moderation_status = moderationFilter.value
    if (search.value) query.search = search.value

    const response = await api.get<Paginated<Product>>('/api/v1/seller/products', query)

    products.value = response.data
    total.value = response.meta.total
  } catch (error) {
    loadError.value = error instanceof ApiError
      ? (error.status === 403 ? 'Ürün ekleyebilmek için satıcı hesabınızın onaylanması gerekiyor.' : error.message)
      : 'Ürünler yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

let searchTimer: ReturnType<typeof setTimeout> | undefined

watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 350)
})

watch(moderationFilter, load)

function coverOf(product: Product): string | null {
  return product.media?.find(item => item.is_cover)?.url ?? product.media?.[0]?.url ?? null
}

function stockOf(product: Product): string {
  const skus = product.skus ?? []

  if (skus.length === 0) return 'Seçenek yok'

  const tracked = skus.filter(sku => sku.stock_quantity !== null)

  if (tracked.length === 0) return `${skus.length} seçenek`

  const units = tracked.reduce((sum, sku) => sum + (sku.stock_quantity ?? 0), 0)

  return `${skus.length} seçenek · ${units} adet`
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-medium">Ürünlerim</h1>
        <p class="mt-1.5 text-sm text-ink-secondary">
          Yayınlanan her ürün, yapay zekânın hazırladığı tasarımlarda müşterilerin
          karşısına çıkabilir. Ürünler incelemeden geçtikten sonra yayına alınır.
        </p>
      </div>

      <RcButton to="/products/new">Yeni ürün</RcButton>
    </header>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <div class="flex flex-wrap items-center gap-3">
      <div class="min-w-[220px] flex-1">
        <input
          v-model="search"
          type="search"
          placeholder="Ürün adı ara"
          class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
        >
      </div>

      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="filter in filters"
          :key="filter.value"
          type="button"
          class="rounded-pill border px-3.5 py-1.5 text-xs transition-colors"
          :class="moderationFilter === filter.value
            ? 'border-charcoal bg-charcoal text-white'
            : 'border-line text-ink-secondary hover:bg-bg-muted'"
          @click="moderationFilter = filter.value"
        >
          {{ filter.label }}
        </button>
      </div>
    </div>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <div v-else-if="products.length === 0" class="rc-card p-12 text-center">
      <RcFeatureIcon
        class="mx-auto"
        size="lg"
        icon="M20.5 7.5 12 3 3.5 7.5m17 0L12 12m8.5-4.5v9L12 21m0-9L3.5 7.5m8.5 4.5v9m-8.5-13.5v9L12 21"
      />
      <h2 class="mt-5 text-lg font-medium">
        {{ search || moderationFilter ? 'Bu filtreyle eşleşen ürün yok' : 'Henüz ürün eklemediniz' }}
      </h2>
      <p class="mx-auto mt-3 max-w-[52ch] leading-relaxed text-ink-secondary">
        Bir ürün eklemek için adı, kategorisi, en az bir görseli ve fiyatı yeterli.
        Ölçüleri girdiğinizde ürününüz tasarım önerilerinde de yer alabilir.
      </p>
      <RcButton to="/products/new" class="mt-7">İlk ürünü ekle</RcButton>
    </div>

    <div v-else class="rc-card overflow-hidden">
      <table class="w-full text-sm">
        <thead class="border-b border-line bg-bg-muted text-left">
          <tr>
            <th class="px-5 py-3 font-medium">Ürün</th>
            <th class="px-5 py-3 font-medium">Durum</th>
            <th class="px-5 py-3 font-medium">Fiyat</th>
            <th class="px-5 py-3 font-medium">Stok</th>
            <th class="px-5 py-3" />
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="product in products"
            :key="product.id"
            class="border-b border-line last:border-0 hover:bg-bg-muted/60"
          >
            <td class="px-5 py-4">
              <div class="flex items-center gap-3">
                <img
                  v-if="coverOf(product)"
                  :src="coverOf(product)!"
                  :alt="product.name"
                  class="size-12 shrink-0 rounded-sm object-cover"
                  loading="lazy"
                >
                <div
                  v-else
                  class="grid size-12 shrink-0 place-items-center rounded-sm bg-bg-muted text-[10px] text-muted"
                >
                  Görsel
                </div>

                <div class="min-w-0">
                  <p class="truncate font-medium">{{ product.name }}</p>
                  <p class="truncate text-xs text-muted">
                    {{ product.category?.name ?? 'Kategorisiz' }}
                  </p>
                </div>
              </div>
            </td>

            <td class="px-5 py-4">
              <div class="flex flex-col gap-1">
                <RcStatusPill
                  :status="product.moderation_status"
                  :label="product.moderation_status_label"
                  size="sm"
                />
                <!-- Approved but paused is a state only the seller can explain, so it
                     is shown alongside rather than folded into one chip. -->
                <RcStatusPill
                  v-if="product.moderation_status === 'approved'"
                  :status="product.status"
                  :label="product.status_label"
                  size="sm"
                />
              </div>
            </td>

            <td class="px-5 py-4 tabular-nums">
              {{ product.lowest_price?.formatted ?? product.from_price?.formatted ?? '—' }}
            </td>

            <td class="px-5 py-4 text-ink-secondary">{{ stockOf(product) }}</td>

            <td class="px-5 py-4 text-right">
              <RcButton :to="`/products/${product.id}`" size="sm" variant="secondary">
                Düzenle
              </RcButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="!loading && products.length > 0" class="text-xs text-muted">
      {{ total }} üründen {{ products.length }} tanesi gösteriliyor.
    </p>
  </div>
</template>
