<script setup lang="ts">
import type { Paginated, Product } from '@refconcept/ui/types'

/**
 * The moderation queue.
 *
 * Defaults to work waiting on a reviewer rather than to the whole catalogue: the
 * question this screen answers is "what needs deciding today". Oldest first, because
 * a queue sorted newest-first starves the listings that have been waiting longest.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Ürün moderasyonu' })

const api = useApi()

const products = ref<Product[]>([])
const total = ref(0)
const loading = ref(true)
const loadError = ref<string | null>(null)
const search = ref('')
const statusFilter = ref('')

const statuses = [
  { value: '', label: 'İnceleme kuyruğu' },
  { value: 'pending_review', label: 'Bekleyen' },
  { value: 'in_review', label: 'İncelemede' },
  { value: 'approved', label: 'Onaylanan' },
  { value: 'rejected', label: 'Reddedilen' },
  { value: 'draft', label: 'Taslak' },
]

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const query: Record<string, string> = {}
    if (statusFilter.value) query.moderation_status = statusFilter.value
    if (search.value) query.search = search.value

    const response = await api.get<Paginated<Product>>('/api/v1/admin/products', query)

    products.value = response.data
    total.value = response.meta.total
  } catch (error) {
    loadError.value = error instanceof ApiError
      ? (error.status === 403 ? 'Bu alana erişim yetkiniz yok.' : error.message)
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

watch(statusFilter, load)

function coverOf(product: Product): string | null {
  return product.media?.find(item => item.is_cover)?.url ?? product.media?.[0]?.url ?? null
}

/** How long this listing has been waiting, which is the queue's real priority signal. */
function waitingSince(product: Product): string {
  const since = product.updated_at

  if (!since) return '—'

  const days = Math.floor((Date.now() - new Date(since).getTime()) / 86_400_000)

  if (days === 0) return 'bugün'

  return `${days} gündür`
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-medium">Ürün moderasyonu</h1>
      <p class="mt-1.5 max-w-[70ch] text-sm leading-relaxed text-ink-secondary">
        Onaylanan her ürün doğrudan müşterinin karşısına çıkar ve tasarım önerilerinde
        kullanılabilir. Her karar gerekçe ister ve denetim kaydına düşer.
      </p>
    </header>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <div class="flex flex-wrap gap-3">
      <div class="min-w-[220px] flex-1">
        <input
          v-model="search"
          type="search"
          placeholder="Ürün adı ara"
          class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
        >
      </div>

      <select
        v-model="statusFilter"
        class="rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
      >
        <option v-for="option in statuses" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>
    </div>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <div v-else-if="products.length === 0" class="rc-card p-10 text-center">
      <p class="text-sm text-ink-secondary">
        {{ statusFilter || search ? 'Bu filtreyle eşleşen ürün yok.' : 'İnceleme bekleyen ürün yok.' }}
      </p>
    </div>

    <div v-else class="rc-card overflow-hidden">
      <table class="w-full text-sm">
        <thead class="border-b border-line bg-bg-muted text-left">
          <tr>
            <th class="px-5 py-3 font-medium">Ürün</th>
            <th class="px-5 py-3 font-medium">Satıcı</th>
            <th class="px-5 py-3 font-medium">Durum</th>
            <th class="px-5 py-3 font-medium">Fiyat</th>
            <th class="px-5 py-3 font-medium">Bekleme</th>
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
                  class="size-11 shrink-0 rounded-sm object-cover"
                  loading="lazy"
                >
                <div
                  v-else
                  class="grid size-11 shrink-0 place-items-center rounded-sm bg-danger-subtle text-[10px] text-danger-strong"
                >
                  yok
                </div>

                <div class="min-w-0">
                  <p class="truncate font-medium">{{ product.name }}</p>
                  <p class="truncate text-xs text-muted">{{ product.category?.name ?? 'Kategorisiz' }}</p>
                </div>
              </div>
            </td>

            <td class="px-5 py-4 text-ink-secondary">
              {{ product.skus?.[0]?.seller?.display_name ?? '—' }}
            </td>

            <td class="px-5 py-4">
              <RcStatusPill
                :status="product.moderation_status"
                :label="product.moderation_status_label"
                size="sm"
              />
            </td>

            <td class="px-5 py-4 tabular-nums">{{ product.lowest_price?.formatted ?? product.from_price?.formatted ?? '—' }}</td>

            <td class="px-5 py-4 text-ink-secondary">{{ waitingSince(product) }}</td>

            <td class="px-5 py-4 text-right">
              <RcButton :to="`/products/${product.id}`" size="sm" variant="secondary">
                İncele
              </RcButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="!loading && products.length > 0" class="text-xs text-muted">
      {{ total }} kayıttan {{ products.length }} tanesi gösteriliyor.
    </p>
  </div>
</template>
