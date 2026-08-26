<script setup lang="ts">
/**
 * The basket.
 *
 * Grouped by seller, because that is what a marketplace basket is: several parcels from
 * several shops, arriving on different days. Pretending otherwise would surprise somebody
 * at the door.
 *
 * The page revalidates on load and shows what moved. A price that rose is stated with both
 * figures and needs accepting; a price that fell is good news and blocks nothing; something
 * that sold out is removed and said so. Finding any of that out at payment instead would
 * be the worst possible moment.
 */
definePageMeta({ middleware: 'auth' })
useSeo({ title: 'Sepetim', noindex: true })

interface CartLine {
  id: string
  sku_id: string
  product: { id: string, name: string | null, slug: string | null, image_url: string | null }
  variant: string | null
  quantity: number
  unit_price_minor: number
  list_price_minor: number
  line_total_minor: number
  price_changed: boolean
  current_price_minor: number | null
}

interface SellerGroup {
  seller_id: string | null
  seller_name: string | null
  subtotal_minor: number
  items: CartLine[]
}

interface CartPayload {
  id: string
  status: string
  status_label: string
  is_editable: boolean
  currency: string
  item_count: number
  subtotal_minor: number
  tax_minor: number
  sellers: SellerGroup[]
}

interface CartIssue {
  item_id: string
  product: string | null
  issue: string
  label: string
  blocks_checkout: boolean
  from: number | null
  to: number | null
}

const api = useApi()

const cart = ref<CartPayload | null>(null)
const issues = ref<CartIssue[]>([])
const busy = ref(false)
const message = ref<string | null>(null)
const loadError = ref<string | null>(null)

async function load() {
  try {
    const response = await api.get<{ data: CartPayload, issues: CartIssue[] }>('/api/v1/cart')
    cart.value = response.data
    issues.value = response.issues
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Sepet yüklenemedi.'
  }
}

await load()

async function act<T>(operation: () => Promise<T>, success?: string) {
  busy.value = true
  message.value = null

  try {
    await operation()
    message.value = success ?? null
    await load()
  } catch (error) {
    message.value = error instanceof ApiError ? error.message : 'İşlem tamamlanamadı.'
  } finally {
    busy.value = false
  }
}

const setQuantity = (line: CartLine, quantity: number) =>
  act(() => api.patch(`/api/v1/cart/items/${line.id}`, { quantity }))

const removeLine = (line: CartLine) =>
  act(() => api.delete(`/api/v1/cart/items/${line.id}`), 'Ürün sepetten çıkarıldı.')

const acceptPrices = () =>
  act(() => api.post('/api/v1/cart/accept-prices'), 'Güncel fiyatlar kabul edildi.')

const abandonCheckout = () =>
  act(() => api.delete('/api/v1/cart/checkout'), 'Sepetiniz yeniden düzenlenebilir.')

/**
 * Off to the payment step.
 *
 * The hold is taken by the checkout page rather than here, so there is exactly one place
 * that reserves stock and exactly one place that gives it back. What this does check is
 * whether anything moved — a customer sent to a payment page that then refuses them has
 * been given a worse version of the same news.
 */
async function beginCheckout() {
  busy.value = true
  message.value = null

  try {
    const response = await api.get<{ data: CartPayload, issues: CartIssue[] }>('/api/v1/cart')

    if (response.issues.some(issue => issue.blocks_checkout)) {
      issues.value = response.issues
      cart.value = response.data
      message.value = 'Sepetinizde değişen ürünler var; kontrol edip tekrar deneyin.'

      return
    }

    await navigateTo('/checkout')
  } catch (error) {
    message.value = error instanceof ApiError ? error.message : 'Ödeme adımına geçilemedi.'
  } finally {
    busy.value = false
  }
}

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}

const blockingIssues = computed(() => issues.value.filter(issue => issue.blocks_checkout))
</script>

<template>
  <div class="rc-container py-10 lg:py-14">
    <h1 class="text-2xl font-medium">Sepetim</h1>

    <RcAlert v-if="loadError" tone="danger" class="mt-6">{{ loadError }}</RcAlert>

    <template v-else-if="cart">
      <p v-if="message" class="mt-4 text-sm text-ink-secondary">{{ message }}</p>

      <!--
        What changed since these things went in. Above everything, because a customer who
        scrolls past it and pays is a customer who was not told.
      -->
      <section v-if="issues.length > 0" class="mt-6 space-y-2">
        <RcAlert
          v-for="issue in issues"
          :key="issue.item_id + issue.issue"
          :tone="issue.blocks_checkout ? 'warning' : 'success'"
        >
          <span class="font-medium">{{ issue.product }}</span> — {{ issue.label }}
          <template v-if="issue.issue === 'price_increased' || issue.issue === 'price_decreased'">
            : {{ money(issue.from ?? 0, cart.currency) }} → {{ money(issue.to ?? 0, cart.currency) }}
          </template>
          <template v-else-if="issue.issue === 'quantity_reduced'">
            : {{ issue.from }} adet yerine {{ issue.to }} adet
          </template>
        </RcAlert>

        <RcButton
          v-if="blockingIssues.some(issue => issue.issue === 'price_increased')"
          size="sm"
          variant="ghost"
          :loading="busy"
          @click="acceptPrices"
        >
          Güncel fiyatları kabul et
        </RcButton>
      </section>

      <p v-if="cart.item_count === 0" class="mt-8 text-sm text-muted">
        Sepetiniz boş. <NuxtLink to="/catalog" class="underline">Ürünlere göz atın</NuxtLink>.
      </p>

      <div v-else class="mt-8 grid gap-8 lg:grid-cols-[1fr_320px]">
        <div class="space-y-8">
          <!-- One block per seller: several parcels, several shops. -->
          <section v-for="group in cart.sellers" :key="group.seller_id ?? ''" class="rc-card p-5 sm:p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
              <h2 class="text-sm font-medium">{{ group.seller_name ?? 'Satıcı' }}</h2>
              <p class="text-sm text-muted tabular-nums">
                {{ money(group.subtotal_minor, cart.currency) }}
              </p>
            </div>

            <ul class="mt-4 divide-y divide-line">
              <li v-for="line in group.items" :key="line.id" class="flex gap-4 py-4">
                <div class="size-20 shrink-0 overflow-hidden rounded-sm bg-bg-muted">
                  <img
                    v-if="line.product.image_url"
                    :src="line.product.image_url"
                    :alt="line.product.name ?? ''"
                    class="size-full object-cover"
                    loading="lazy"
                  >
                </div>

                <div class="min-w-0 flex-1">
                  <NuxtLink
                    v-if="line.product.slug"
                    :to="`/catalog/${line.product.slug}`"
                    class="line-clamp-2 text-sm hover:underline"
                  >
                    {{ line.product.name }}
                  </NuxtLink>
                  <p v-else class="line-clamp-2 text-sm">{{ line.product.name }}</p>

                  <p v-if="line.variant" class="mt-0.5 text-xs text-muted">{{ line.variant }}</p>

                  <!--
                    Both figures when they differ. Showing only the new one would be a
                    silent repricing; showing only the old one would be a lie at the till.
                  -->
                  <p class="mt-1.5 text-sm tabular-nums">
                    {{ money(line.unit_price_minor, cart.currency) }}
                    <span
                      v-if="line.price_changed && line.current_price_minor !== null"
                      class="text-warning"
                    >
                      → {{ money(line.current_price_minor, cart.currency) }}
                    </span>
                  </p>

                  <div v-if="cart.is_editable" class="mt-2.5 flex items-center gap-2">
                    <label :for="`qty-${line.id}`" class="sr-only">Adet</label>
                    <input
                      :id="`qty-${line.id}`"
                      type="number"
                      min="1"
                      max="99"
                      :value="line.quantity"
                      :disabled="busy"
                      class="w-16 rounded-sm border border-line bg-surface px-2 py-1 text-sm tabular-nums"
                      @change="setQuantity(line, Number(($event.target as HTMLInputElement).value))"
                    >
                    <button
                      type="button"
                      class="text-xs text-muted underline hover:text-ink disabled:opacity-40"
                      :disabled="busy"
                      @click="removeLine(line)"
                    >
                      Çıkar
                    </button>
                  </div>
                  <p v-else class="mt-2.5 text-xs text-muted">{{ line.quantity }} adet</p>
                </div>

                <p class="shrink-0 text-sm font-medium tabular-nums">
                  {{ money(line.line_total_minor, cart.currency) }}
                </p>
              </li>
            </ul>
          </section>
        </div>

        <aside class="rc-card h-fit p-5 sm:p-6">
          <h2 class="text-sm font-medium">Özet</h2>

          <dl class="mt-4 space-y-2 text-sm">
            <div class="flex justify-between gap-4">
              <dt class="text-ink-secondary">Ara toplam</dt>
              <dd class="tabular-nums">{{ money(cart.subtotal_minor, cart.currency) }}</dd>
            </div>
            <!--
              KDV is part of the price in Turkey, not an addition. Listing it as an extra
              would inflate every total by a fifth.
            -->
            <div class="flex justify-between gap-4 text-muted">
              <dt>Dâhil KDV</dt>
              <dd class="tabular-nums">{{ money(cart.tax_minor, cart.currency) }}</dd>
            </div>
          </dl>

          <p class="mt-4 border-t border-line pt-4 text-xs text-muted">
            Kargo ve teslimat seçenekleri ödeme adımında hesaplanır.
          </p>

          <RcButton
            v-if="cart.is_editable"
            class="mt-4 w-full"
            :loading="busy"
            :disabled="busy || cart.item_count === 0"
            @click="beginCheckout"
          >
            Ödemeye geç
          </RcButton>

          <template v-else>
            <p class="mt-4 rounded-sm bg-bg-muted px-3 py-2 text-xs text-ink-secondary">
              Sepetiniz ödeme adımında ve ürünleriniz sizin için ayrıldı.
            </p>
            <RcButton class="mt-3 w-full" to="/checkout">Ödemeye devam et</RcButton>
            <RcButton class="mt-2 w-full" variant="ghost" :loading="busy" @click="abandonCheckout">
              Sepete dön
            </RcButton>
          </template>
        </aside>
      </div>
    </template>
  </div>
</template>
