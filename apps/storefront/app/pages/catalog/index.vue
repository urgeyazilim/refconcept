<script setup lang="ts">
import type { CatalogCategory, CatalogVocabulary, Paginated, Product } from '@refconcept/ui/types'

/**
 * The public catalogue.
 *
 * Filters live in the URL, not in component state. A customer who has narrowed to
 * "oturma odası, modern, 20.000 ₺ altı" should be able to send that link to somebody
 * — and should get the same page back after a refresh. It also means the back button
 * undoes a filter instead of leaving the page.
 *
 * Everything here is unauthenticated, and every product comes back through the API's
 * `publiclyVisible` scope, so a draft or a suspended seller's listing cannot appear
 * however the filters are combined.
 */
useSeo({
  title: 'Ürünler',
  description:
    'RefConcept pazar yerindeki mobilya ve dekorasyon ürünleri. Odanıza uygun parçaları '
    + 'stil, oda tipi ve bütçeye göre filtreleyin.',
})

const api = useApi()
const route = useRoute()
const router = useRouter()

const products = ref<Product[]>([])
const total = ref(0)
const currentPage = ref(1)
const lastPage = ref(1)
const loading = ref(true)
const loadError = ref<string | null>(null)

const categories = ref<CatalogCategory[]>([])
const vocabulary = ref<CatalogVocabulary>({ colors: [], materials: [], styles: [] })

const sorts = [
  { value: 'newest', label: 'En yeniler' },
  { value: 'price_asc', label: 'Artan fiyat' },
  { value: 'price_desc', label: 'Azalan fiyat' },
  { value: 'name', label: 'İsme göre' },
]

const rooms = [
  { value: '', label: 'Tüm odalar' },
  { value: 'living_room', label: 'Oturma odası' },
  { value: 'bedroom', label: 'Yatak odası' },
  { value: 'dining_room', label: 'Yemek odası' },
  { value: 'kitchen', label: 'Mutfak' },
  { value: 'bathroom', label: 'Banyo' },
  { value: 'office', label: 'Çalışma odası' },
  { value: 'kids_room', label: 'Çocuk odası' },
]

/** The URL is the single source of truth; the form reads from it. */
const filters = reactive({
  search: (route.query.search as string) ?? '',
  category: (route.query.category as string) ?? '',
  room_type: (route.query.room_type as string) ?? '',
  style: (route.query.style as string) ?? '',
  min_price: (route.query.min_price as string) ?? '',
  max_price: (route.query.max_price as string) ?? '',
  sort: (route.query.sort as string) ?? 'newest',
})

const [categoryResponse, vocabularyResponse] = await Promise.all([
  api.get<{ data: CatalogCategory[] }>('/api/v1/catalog/categories'),
  api.get<{ data: CatalogVocabulary }>('/api/v1/catalog/vocabulary'),
])

categories.value = categoryResponse.data
vocabulary.value = vocabularyResponse.data

/** Top-level branches only: a 40-entry flat list is not a filter, it is a wall. */
const topCategories = computed(() => categories.value.filter(item => item.parent_id === null))

const activeFilterCount = computed(() =>
  [filters.category, filters.room_type, filters.style, filters.min_price, filters.max_price]
    .filter(Boolean).length,
)

async function load(page = 1) {
  loading.value = true
  loadError.value = null

  try {
    const query: Record<string, string | number> = { per_page: 24, page }

    if (filters.search) query.search = filters.search
    if (filters.category) query.category = filters.category
    if (filters.room_type) query.room_type = filters.room_type
    if (filters.style) query.style = filters.style
    if (filters.sort) query.sort = filters.sort

    // Budget filters travel as minor units, like every other amount in the system.
    const min = inputToMinor(filters.min_price)
    const max = inputToMinor(filters.max_price)

    if (min !== null) query.min_price_minor = min
    if (max !== null) query.max_price_minor = max

    const response = await api.get<Paginated<Product>>('/api/v1/catalog/products', query)

    products.value = response.data
    total.value = response.meta.total
    currentPage.value = response.meta.current_page
    lastPage.value = response.meta.last_page
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Ürünler yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load(Number(route.query.page ?? 1))

/** Writing the filters back to the URL is what makes the page shareable. */
function syncUrl() {
  const query: Record<string, string> = {}

  for (const [key, value] of Object.entries(filters)) {
    if (value && !(key === 'sort' && value === 'newest')) query[key] = value
  }

  router.replace({ query })
}

let debounce: ReturnType<typeof setTimeout> | undefined

watch(filters, () => {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    syncUrl()
    load(1)
  }, 300)
})

function clearFilters() {
  filters.category = ''
  filters.room_type = ''
  filters.style = ''
  filters.min_price = ''
  filters.max_price = ''
}

function goToPage(page: number) {
  if (page < 1 || page > lastPage.value) return

  load(page)

  if (import.meta.client) window.scrollTo({ top: 0, behavior: 'smooth' })
}
</script>

<template>
  <div>
    <section class="border-b border-line bg-bg-muted">
      <div class="rc-container py-12 sm:py-16">
        <h1 class="text-3xl font-medium tracking-tight sm:text-4xl">Ürünler</h1>
        <p class="mt-4 max-w-[62ch] leading-relaxed text-ink-secondary">
          RefConcept'te satılan her ürün, onaylı satıcılar tarafından listelenir ve
          yayına alınmadan önce ekibimizin incelemesinden geçer. Ölçüleri girilmiş
          ürünler, yapay zekânın hazırladığı oda tasarımlarında da kullanılabilir.
        </p>
      </div>
    </section>

    <div class="rc-container grid gap-10 py-10 lg:grid-cols-[240px_minmax(0,1fr)]">
      <!-- Filters -->
      <aside class="space-y-7 lg:sticky lg:top-24 lg:self-start">
        <div>
          <label for="search" class="mb-2 block text-sm font-medium">Ara</label>
          <input
            id="search"
            v-model="filters.search"
            type="search"
            placeholder="Kanepe, halı, aydınlatma…"
            class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
          >
        </div>

        <div>
          <p class="mb-2.5 text-sm font-medium">Kategori</p>
          <div class="space-y-1">
            <button
              type="button"
              class="block w-full rounded-sm px-3 py-2 text-left text-sm transition-colors"
              :class="filters.category === '' ? 'bg-bg-muted font-medium' : 'text-ink-secondary hover:bg-bg-muted'"
              @click="filters.category = ''"
            >
              Tümü
            </button>
            <button
              v-for="category in topCategories"
              :key="category.id"
              type="button"
              class="block w-full rounded-sm px-3 py-2 text-left text-sm transition-colors"
              :class="filters.category === category.slug ? 'bg-bg-muted font-medium' : 'text-ink-secondary hover:bg-bg-muted'"
              @click="filters.category = category.slug"
            >
              {{ category.name }}
            </button>
          </div>
        </div>

        <div>
          <label for="room_type" class="mb-2 block text-sm font-medium">Oda</label>
          <select
            id="room_type"
            v-model="filters.room_type"
            class="w-full rounded-sm border border-line bg-surface px-3 py-2.5 text-sm"
          >
            <option v-for="room in rooms" :key="room.value" :value="room.value">
              {{ room.label }}
            </option>
          </select>
        </div>

        <div v-if="vocabulary.styles.length > 0">
          <p class="mb-2.5 text-sm font-medium">Stil</p>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="style in vocabulary.styles"
              :key="style.code"
              type="button"
              class="rounded-pill border px-3 py-1.5 text-xs transition-colors"
              :class="filters.style === style.code
                ? 'border-charcoal bg-charcoal text-white'
                : 'border-line text-ink-secondary hover:bg-bg-muted'"
              @click="filters.style = filters.style === style.code ? '' : style.code"
            >
              {{ style.name }}
            </button>
          </div>
        </div>

        <div>
          <p class="mb-2.5 text-sm font-medium">Bütçe (₺)</p>
          <div class="flex items-center gap-2">
            <input
              v-model="filters.min_price"
              type="text"
              inputmode="decimal"
              placeholder="En az"
              aria-label="En düşük fiyat"
              class="w-full rounded-sm border border-line bg-surface px-3 py-2.5 text-sm"
            >
            <span class="text-muted">–</span>
            <input
              v-model="filters.max_price"
              type="text"
              inputmode="decimal"
              placeholder="En çok"
              aria-label="En yüksek fiyat"
              class="w-full rounded-sm border border-line bg-surface px-3 py-2.5 text-sm"
            >
          </div>
        </div>

        <button
          v-if="activeFilterCount > 0"
          type="button"
          class="text-sm text-ink-secondary underline underline-offset-4 hover:text-ink"
          @click="clearFilters"
        >
          Filtreleri temizle ({{ activeFilterCount }})
        </button>
      </aside>

      <!-- Results -->
      <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <p class="text-sm text-ink-secondary">
            <span v-if="loading">Yükleniyor…</span>
            <span v-else>{{ total }} ürün</span>
          </p>

          <div class="flex items-center gap-2.5">
            <label for="sort" class="text-sm text-muted">Sırala</label>
            <select
              id="sort"
              v-model="filters.sort"
              class="rounded-sm border border-line bg-surface px-3 py-2 text-sm"
            >
              <option v-for="option in sorts" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </div>
        </div>

        <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

        <div v-else-if="!loading && products.length === 0" class="rc-card p-14 text-center">
          <h2 class="text-lg font-medium">Bu filtrelerle eşleşen ürün yok</h2>
          <p class="mx-auto mt-3 max-w-[48ch] leading-relaxed text-ink-secondary">
            Bütçe aralığını genişletmeyi ya da kategori seçimini kaldırmayı deneyin.
          </p>
          <RcButton class="mt-7" variant="secondary" @click="clearFilters">
            Filtreleri temizle
          </RcButton>
        </div>

        <div v-else class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
          <ProductCard v-for="product in products" :key="product.id" :product="product" />
        </div>

        <nav v-if="lastPage > 1" class="flex items-center justify-center gap-3 pt-4">
          <RcButton
            size="sm"
            variant="secondary"
            :disabled="currentPage === 1"
            @click="goToPage(currentPage - 1)"
          >
            Önceki
          </RcButton>
          <span class="text-sm text-muted tabular-nums">{{ currentPage }} / {{ lastPage }}</span>
          <RcButton
            size="sm"
            variant="secondary"
            :disabled="currentPage === lastPage"
            @click="goToPage(currentPage + 1)"
          >
            Sonraki
          </RcButton>
        </nav>
      </div>
    </div>
  </div>
</template>
