<script setup lang="ts">
import type { Product, ProductSkuItem } from '@refconcept/ui/types'

/**
 * A product page.
 *
 * Several sellers can offer the same catalogue entry, so the page is organised
 * around choosing an offer rather than around a single price: the cheapest available
 * one is selected on arrival, and the others are listed with the seller's name so a
 * customer can see who they would be buying from.
 *
 * Dimensions get their own block rather than being buried in a specification table.
 * They are what decides whether the piece fits, and — once the design engine lands in
 * Phase 9 — what lets RefConcept place it in a room at all.
 */
const route = useRoute()
const api = useApi()
const slug = route.params.slug as string

const product = ref<Product | null>(null)
const notFound = ref(false)
const loadError = ref<string | null>(null)
const activeImage = ref(0)
const selectedSkuId = ref<string | null>(null)

try {
  const response = await api.get<{ data: Product }>(`/api/v1/catalog/products/${slug}`)

  product.value = response.data

  const available = (response.data.skus ?? [])
    .filter(sku => sku.is_available)
    .sort((a, b) => a.effective_price.amount_minor - b.effective_price.amount_minor)

  selectedSkuId.value = available[0]?.id ?? response.data.skus?.[0]?.id ?? null
} catch (error) {
  if (error instanceof ApiError && error.status === 404) notFound.value = true
  else loadError.value = error instanceof ApiError ? error.message : 'Ürün yüklenemedi.'
}

useHead(() => ({
  title: product.value?.name ?? 'Ürün',
  meta: [
    {
      name: 'description',
      content: (product.value?.description ?? '').slice(0, 160),
    },
  ],
}))

const images = computed(() => product.value?.media ?? [])
const offers = computed(() => product.value?.skus ?? [])

const selectedSku = computed<ProductSkuItem | null>(() =>
  offers.value.find(sku => sku.id === selectedSkuId.value) ?? null,
)

const dimensions = computed(() => selectedSku.value?.dimensions ?? null)

/** VAT on the chosen offer, shown because furniture prices are quoted both ways. */
const taxNote = computed(() => {
  const sku = selectedSku.value

  if (!sku) return null

  return `Fiyata %${bpsToPercent(sku.tax_rate_bps)} KDV dahildir.`
})

const quantity = ref(1)
const addingToCart = ref(false)
const favoriting = ref(false)
const isFavorite = ref(false)
const cartMessage = ref<string | null>(null)
const cartError = ref(false)

const { isAuthenticated } = useAuth()

/**
 * Whether this product is already on the customer's list.
 *
 * Asked once on load, and only when somebody is signed in — a flag on every catalogue
 * response would mean a join on a listing anonymous visitors also read.
 */
async function loadFavoriteState() {
  if (!isAuthenticated.value || !product.value) return

  try {
    const response = await api.post<{ data: string[] }>('/api/v1/favorites/check', {
      product_ids: [product.value.id],
    })

    isFavorite.value = response.data.length > 0
  } catch {
    // A favourite indicator that fails to load is not worth an error message.
  }
}

await loadFavoriteState()

async function addToCart() {
  if (!selectedSku.value) return

  addingToCart.value = true
  cartMessage.value = null
  cartError.value = false

  try {
    await api.post('/api/v1/cart/items', {
      sku_id: selectedSku.value.id,
      quantity: quantity.value,
    })

    cartMessage.value = 'Ürün sepetinize eklendi.'
  } catch (error) {
    cartError.value = true
    cartMessage.value = error instanceof ApiError
      ? (error.isUnauthorized ? 'Sepete eklemek için giriş yapın.' : error.message)
      : 'Ürün sepete eklenemedi.'
  } finally {
    addingToCart.value = false
  }
}

async function toggleFavorite() {
  if (!product.value) return

  favoriting.value = true
  cartMessage.value = null
  cartError.value = false

  try {
    if (isFavorite.value) {
      await api.delete(`/api/v1/favorites/${product.value.id}`)
      isFavorite.value = false
    } else {
      await api.post(`/api/v1/favorites/${product.value.id}`)
      isFavorite.value = true
    }
  } catch (error) {
    cartError.value = true
    cartMessage.value = error instanceof ApiError
      ? (error.isUnauthorized ? 'Favorilere eklemek için giriş yapın.' : error.message)
      : 'İşlem tamamlanamadı.'
  } finally {
    favoriting.value = false
  }
}

const deliveryNote = computed(() => {
  const sku = selectedSku.value

  if (!sku) return null

  if (sku.stock_policy === 'made_to_order') {
    return `Siparişe özel üretilir, yaklaşık ${sku.lead_time_days} gün içinde hazırlanır.`
  }

  return `Yaklaşık ${sku.lead_time_days} gün içinde kargoya verilir.`
})
</script>

<template>
  <div>
    <div v-if="notFound" class="rc-container py-24 text-center">
      <h1 class="text-2xl font-medium">Bu ürün bulunamadı</h1>
      <p class="mx-auto mt-4 max-w-[48ch] leading-relaxed text-ink-secondary">
        Ürün kaldırılmış ya da satıcısı tarafından yayından çekilmiş olabilir.
      </p>
      <RcButton to="/catalog" class="mt-8">Kataloğa dön</RcButton>
    </div>

    <div v-else-if="loadError" class="rc-container py-24">
      <RcAlert tone="danger">{{ loadError }}</RcAlert>
    </div>

    <div v-else-if="product" class="rc-container py-8 sm:py-12">
      <nav class="flex flex-wrap items-center gap-2 text-sm text-muted">
        <NuxtLink to="/catalog" class="hover:text-ink">Ürünler</NuxtLink>
        <span>/</span>
        <NuxtLink
          v-if="product.category"
          :to="`/catalog?category=${product.category.slug}`"
          class="hover:text-ink"
        >
          {{ product.category.name }}
        </NuxtLink>
        <span v-if="product.category">/</span>
        <span class="text-ink-secondary">{{ product.name }}</span>
      </nav>

      <div class="mt-8 grid gap-10 lg:grid-cols-[minmax(0,1fr)_400px]">
        <!-- Gallery -->
        <div class="space-y-3">
          <div class="overflow-hidden rounded-md border border-line bg-bg-muted">
            <div class="aspect-[4/3]">
              <img
                v-if="images.length > 0"
                :src="images[activeImage]?.url"
                :alt="images[activeImage]?.alt_text ?? product.name"
                class="size-full object-cover"
              >
              <div v-else class="grid size-full place-items-center text-sm text-muted">
                Görsel yok
              </div>
            </div>
          </div>

          <div v-if="images.length > 1" class="flex gap-2.5 overflow-x-auto pb-1">
            <button
              v-for="(image, index) in images"
              :key="image.id"
              type="button"
              class="size-20 shrink-0 overflow-hidden rounded-sm border-2 transition-colors"
              :class="index === activeImage ? 'border-charcoal' : 'border-line hover:border-line-strong'"
              :aria-label="`Görsel ${index + 1}`"
              @click="activeImage = index"
            >
              <img :src="image.url" :alt="image.alt_text ?? ''" class="size-full object-cover">
            </button>
          </div>
        </div>

        <!-- Buying panel -->
        <div class="space-y-7">
          <div>
            <p v-if="product.brand" class="text-xs tracking-wide text-muted uppercase">
              {{ product.brand.name }}
            </p>
            <h1 class="mt-1.5 text-2xl leading-tight font-medium sm:text-3xl">{{ product.name }}</h1>
            <p v-if="product.style" class="mt-2 text-sm text-ink-secondary">
              {{ product.style.name }} stil
            </p>
          </div>

          <div v-if="selectedSku">
            <p class="text-3xl font-medium tabular-nums">
              {{ selectedSku.effective_price.formatted }}
            </p>
            <p v-if="selectedSku.discount_bps > 0" class="mt-1 text-sm text-muted">
              <s class="tabular-nums">{{ selectedSku.list_price.formatted }}</s>
              · %{{ bpsToWholePercent(selectedSku.discount_bps) }} indirim
            </p>
            <p v-if="taxNote" class="mt-1.5 text-xs text-muted">{{ taxNote }}</p>
          </div>

          <!-- Offers -->
          <div v-if="offers.length > 1">
            <p class="mb-2.5 text-sm font-medium">Satış seçenekleri</p>
            <div class="space-y-2">
              <button
                v-for="sku in offers"
                :key="sku.id"
                type="button"
                class="flex w-full items-center justify-between gap-4 rounded-md border px-4 py-3 text-left text-sm transition-colors"
                :class="[
                  sku.id === selectedSkuId ? 'border-charcoal bg-bg-muted' : 'border-line hover:bg-bg-muted',
                  !sku.is_available && 'opacity-50',
                ]"
                :disabled="!sku.is_available"
                @click="selectedSkuId = sku.id"
              >
                <span class="min-w-0">
                  <span class="block truncate font-medium">
                    {{ sku.variant_label ?? sku.sku }}
                  </span>
                  <span v-if="sku.seller" class="block truncate text-xs text-muted">
                    {{ sku.seller.display_name }}
                  </span>
                </span>
                <span class="shrink-0 tabular-nums">{{ sku.effective_price.formatted }}</span>
              </button>
            </div>
          </div>

          <div v-else-if="selectedSku?.seller" class="text-sm text-ink-secondary">
            Satıcı: <span class="text-ink">{{ selectedSku.seller.display_name }}</span>
          </div>

          <!-- Availability -->
          <div class="rounded-md bg-bg-muted p-4 text-sm">
            <p v-if="selectedSku?.is_available" class="flex items-center gap-2.5 text-success-strong">
              <svg class="rc-icon size-4" viewBox="0 0 24 24" aria-hidden="true">
                <path d="m5 13 4 4L19 7" />
              </svg>
              Satışta
            </p>
            <p v-else class="text-warning-strong">Bu seçenek şu anda satışta değil.</p>
            <p v-if="deliveryNote" class="mt-1.5 text-ink-secondary">{{ deliveryNote }}</p>
          </div>

          <!-- Basket -->
          <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-3">
              <label for="quantity" class="sr-only">Adet</label>
              <input
                id="quantity"
                v-model.number="quantity"
                type="number"
                min="1"
                max="99"
                class="w-20 rounded-sm border border-line bg-surface px-3 py-2.5 text-sm tabular-nums"
              >

              <RcButton
                :loading="addingToCart"
                :disabled="addingToCart || !selectedSku?.is_available"
                @click="addToCart"
              >
                Sepete ekle
              </RcButton>

              <button
                type="button"
                class="rounded-sm border border-line px-3 py-2.5 text-sm text-ink-secondary transition-colors hover:bg-bg-muted hover:text-ink disabled:opacity-40"
                :disabled="favoriting"
                :aria-pressed="isFavorite"
                @click="toggleFavorite"
              >
                {{ isFavorite ? 'Favorilerimde' : 'Favorilere ekle' }}
              </button>
            </div>

            <!--
              Whatever the API said, in its own words. A basket refusal carries the number
              that matters — "yalnızca 2 adet kaldı" — and paraphrasing it here would lose
              exactly the part the customer needs.
            -->
            <p v-if="cartMessage" class="text-sm" :class="cartError ? 'text-danger' : 'text-success'">
              {{ cartMessage }}
            </p>
          </div>

          <!-- Dimensions -->
          <div v-if="dimensions">
            <h2 class="text-sm font-medium">Ölçüler</h2>
            <dl class="mt-3 space-y-2 text-sm">
              <div class="flex justify-between border-b border-line pb-2">
                <dt class="text-muted">Genişlik</dt>
                <dd class="tabular-nums">{{ mmToCm(dimensions.width_mm) }}</dd>
              </div>
              <div class="flex justify-between border-b border-line pb-2">
                <dt class="text-muted">Derinlik</dt>
                <dd class="tabular-nums">{{ mmToCm(dimensions.depth_mm) }}</dd>
              </div>
              <div v-if="dimensions.height_mm" class="flex justify-between border-b border-line pb-2">
                <dt class="text-muted">Yükseklik</dt>
                <dd class="tabular-nums">{{ mmToCm(dimensions.height_mm) }}</dd>
              </div>
              <div v-if="dimensions.assembly_required" class="flex justify-between pb-2">
                <dt class="text-muted">Kurulum</dt>
                <dd>Gerekiyor</dd>
              </div>
            </dl>
          </div>
        </div>
      </div>

      <!-- Description and attributes -->
      <div class="mt-14 grid gap-10 border-t border-line pt-10 lg:grid-cols-2">
        <section>
          <h2 class="text-lg font-medium">Ürün açıklaması</h2>
          <p class="mt-4 leading-relaxed whitespace-pre-line text-ink-secondary">
            {{ product.description }}
          </p>
        </section>

        <section v-if="product.attributes?.length">
          <h2 class="text-lg font-medium">Özellikler</h2>
          <dl class="mt-4 space-y-2.5 text-sm">
            <div
              v-for="attribute in product.attributes"
              :key="attribute.code ?? attribute.name ?? ''"
              class="flex justify-between gap-6 border-b border-line pb-2.5"
            >
              <dt class="text-muted">{{ attribute.name }}</dt>
              <dd class="text-right">
                {{ attribute.display }}<span v-if="attribute.unit"> {{ attribute.unit }}</span>
              </dd>
            </div>
          </dl>
        </section>
      </div>
    </div>
  </div>
</template>
