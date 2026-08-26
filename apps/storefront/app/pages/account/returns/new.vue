<script setup lang="ts">
/**
 * Asking to send something back.
 *
 * Per line and per quantity, because returning one of four chairs is the ordinary case —
 * an all-or-nothing form turns it into an e-mail to support.
 *
 * The seller is named on the page. A customer who bought from three shops in one order is
 * returning to one of them, and being clear about which avoids a parcel going to the wrong
 * warehouse.
 */
import type { OrderDetail, ReturnReason } from '@refconcept/ui/types'

definePageMeta({ layout: 'account', middleware: ['auth', 'verified'] })
useSeo({ title: 'İade talebi', noindex: true })

const api = useApi()
const route = useRoute()
const router = useRouter()

const orderNumber = computed(() => String(route.query.order ?? ''))
const sellerOrderNumber = computed(() => String(route.query.seller ?? ''))

const order = ref<OrderDetail | null>(null)
const reasons = ref<ReturnReason[]>([])
const loadError = ref<string | null>(null)

/** How many of each line the customer wants to send back. */
const quantities = reactive<Record<string, number>>({})

const reasonCode = ref('')
const note = ref('')
const busy = ref(false)
const formError = ref<string | null>(null)

try {
  const [detail, reasonList] = await Promise.all([
    api.get<{ data: OrderDetail }>(`/api/v1/orders/${orderNumber.value}`),
    api.get<{ data: ReturnReason[] }>('/api/v1/returns/reasons'),
  ])

  order.value = detail.data
  reasons.value = reasonList.data
  reasonCode.value = reasonList.data[0]?.code ?? ''
} catch (error) {
  loadError.value = error instanceof ApiError ? error.message : 'Sipariş yüklenemedi.'
}

const group = computed(() =>
  order.value?.sellers.find(entry => entry.seller_order_number === sellerOrderNumber.value) ?? null,
)

const selected = computed(() =>
  Object.entries(quantities)
    .filter(([, quantity]) => quantity > 0)
    .map(([orderItemId, quantity]) => ({ order_item_id: orderItemId, quantity })),
)

const total = computed(() => {
  const lines = group.value?.items ?? []

  return selected.value.reduce((sum, entry) => {
    const line = lines.find(item => item.id === entry.order_item_id)

    return sum + (line?.unit_price_minor ?? 0) * entry.quantity
  }, 0)
})

async function submit() {
  if (selected.value.length === 0) {
    formError.value = 'İade etmek istediğiniz ürünleri seçin.'

    return
  }

  busy.value = true
  formError.value = null

  try {
    await api.post('/api/v1/returns', {
      seller_order_number: sellerOrderNumber.value,
      reason_code: reasonCode.value,
      reason_note: note.value || undefined,
      items: selected.value,
    })

    await router.push('/account/returns')
  } catch (error) {
    formError.value = error instanceof ApiError ? error.message : 'İade talebi oluşturulamadı.'
  } finally {
    busy.value = false
  }
}

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}
</script>

<template>
  <div>
    <h1 class="text-xl font-medium">İade talebi</h1>

    <RcAlert v-if="loadError" tone="danger" class="mt-6">{{ loadError }}</RcAlert>

    <RcAlert v-else-if="!group" tone="info" class="mt-6">
      Bu sipariş için iade edilebilecek bir bölüm bulunamadı.
      <NuxtLink to="/account/orders" class="ml-1 underline">Siparişlerime dön</NuxtLink>
    </RcAlert>

    <template v-else-if="order">
      <p class="mt-1 text-sm text-muted">
        {{ group.seller_name ?? 'Satıcı' }} · {{ group.seller_order_number }}
      </p>

      <section class="rc-card mt-6 p-5 sm:p-6">
        <h2 class="text-sm font-medium">Hangi ürünleri iade ediyorsunuz?</h2>

        <ul class="mt-4 divide-y divide-line">
          <li v-for="item in group.items" :key="item.id" class="flex flex-wrap items-center gap-4 py-4">
            <div class="min-w-0 flex-1">
              <p class="text-sm">{{ item.product_name }}</p>
              <p class="text-xs text-muted">
                {{ item.quantity }} adet · {{ money(item.unit_price_minor, order.currency) }}
              </p>
            </div>

            <!-- Per line and per quantity: one of four is the ordinary case. -->
            <div class="flex items-center gap-2">
              <label :for="`qty-${item.id}`" class="text-xs text-muted">İade adedi</label>
              <input
                :id="`qty-${item.id}`"
                v-model.number="quantities[item.id]"
                type="number"
                min="0"
                :max="item.quantity"
                class="w-20 rounded-sm border border-line bg-surface px-2 py-1 text-sm tabular-nums"
              >
            </div>
          </li>
        </ul>
      </section>

      <section class="rc-card mt-6 p-5 sm:p-6">
        <h2 class="text-sm font-medium">Sebep</h2>

        <div class="mt-4 space-y-2">
          <label v-for="reason in reasons" :key="reason.code" class="flex items-center gap-2 text-sm">
            <input v-model="reasonCode" type="radio" :value="reason.code">
            {{ reason.label }}
          </label>
        </div>

        <div class="mt-4">
          <label for="note" class="mb-1.5 block text-sm font-medium">Açıklama</label>
          <textarea
            id="note"
            v-model="note"
            rows="3"
            maxlength="500"
            class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
            placeholder="Satıcının bilmesi gerekenler"
          />
        </div>
      </section>

      <div class="mt-6 flex flex-wrap items-center gap-4">
        <RcButton :loading="busy" :disabled="busy || selected.length === 0" @click="submit">
          İade talebi oluştur
        </RcButton>

        <p v-if="total > 0" class="text-sm text-ink-secondary">
          Talep edilen tutar: <span class="font-medium tabular-nums">{{ money(total, order.currency) }}</span>
        </p>
      </div>

      <p v-if="formError" class="mt-3 text-sm text-danger">{{ formError }}</p>

      <!--
        Said before the request is sent, not after it is refused: the amount is what the
        seller decides on, and they can accept some of it.
      -->
      <p class="mt-4 text-xs text-muted">
        Satıcı ürünleri inceledikten sonra iadenizi tamamen veya kısmen onaylayabilir. Onay
        sonrası ücret iadesi ödeme yönteminize yapılır.
      </p>
    </template>
  </div>
</template>
