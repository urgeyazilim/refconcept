<script setup lang="ts">
/**
 * Parcels.
 *
 * A shipment is a physical thing with a carrier and a tracking number, and one order can
 * have several — a sofa and its cushions leave on different days. So the unit of work here
 * is the parcel, not the order, and the seller says which lines are in it.
 *
 * The remaining quantities come from the server. The alternative is this page subtracting
 * shipment lines from order lines itself, which is arithmetic that has to be right in every
 * client — and a seller doing it in their head while looking at a screen that already knows
 * the answer.
 *
 * The order leaves "hazırlanıyor" on its own once everything has gone. A seller marking it
 * shipped by hand while a chair is still in the warehouse gives their customer a status
 * that will be wrong for a week.
 */
import type { PendingShipmentLine, SellerOrderSummary, ShipmentSummary } from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Kargo' })

const api = useApi()

const orders = ref<SellerOrderSummary[]>([])
const active = ref<SellerOrderSummary | null>(null)
const shipments = ref<ShipmentSummary[]>([])
const pending = ref<PendingShipmentLine[]>([])

const loading = ref(true)
const busy = ref(false)
const loadError = ref<string | null>(null)
const banner = ref<{ tone: 'success' | 'danger', text: string } | null>(null)

const carrier = ref('')
const trackingNumber = ref('')

/** How many of each line go in this parcel, keyed by order item. */
const quantities = ref<Record<string, number>>({})

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const response = await api.get<{ data: SellerOrderSummary[] }>('/api/v1/seller/orders')

    // Only what can actually be put in a box. An order still awaiting confirmation is the
    // orders screen's business, and one already delivered is nobody's.
    orders.value = response.data.filter(
      order => order.status === 'confirmed' || order.status === 'preparing' || order.status === 'shipped',
    )

    if (active.value !== null) {
      const stillThere = orders.value.find(
        order => order.seller_order_number === active.value?.seller_order_number,
      )

      active.value = stillThere ?? null
    }
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Siparişler yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

async function open(order: SellerOrderSummary) {
  active.value = order
  banner.value = null
  carrier.value = ''
  trackingNumber.value = ''

  try {
    const response = await api.get<{ data: ShipmentSummary[], meta: { pending: PendingShipmentLine[] } }>(
      `/api/v1/seller/orders/${order.seller_order_number}/shipments`,
    )

    shipments.value = response.data
    pending.value = response.meta.pending

    // Pre-filled with everything that is left, because shipping the whole remainder is the
    // ordinary case and typing the same numbers back in is not work.
    quantities.value = Object.fromEntries(pending.value.map(line => [line.order_item_id, line.remaining]))
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'Kargo bilgileri yüklenemedi.',
    }
  }
}

const chosen = computed(() =>
  pending.value
    .map(line => ({ order_item_id: line.order_item_id, quantity: Number(quantities.value[line.order_item_id] ?? 0) }))
    .filter(line => line.quantity > 0),
)

async function ship() {
  if (active.value === null || chosen.value.length === 0) {
    return
  }

  busy.value = true
  banner.value = null

  try {
    await api.post(`/api/v1/seller/orders/${active.value.seller_order_number}/shipments`, {
      carrier: carrier.value === '' ? null : carrier.value,
      tracking_number: trackingNumber.value === '' ? null : trackingNumber.value,
      items: chosen.value,
    })

    banner.value = { tone: 'success', text: 'Kargo kaydedildi.' }

    const order = active.value

    await load()
    await open(order)
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'Kargo kaydedilemedi.',
    }
  } finally {
    busy.value = false
  }
}

async function markDelivered(shipment: ShipmentSummary) {
  if (active.value === null) {
    return
  }

  busy.value = true
  banner.value = null

  try {
    await api.post(
      `/api/v1/seller/orders/${active.value.seller_order_number}/shipments/${shipment.id}/delivered`,
    )

    banner.value = { tone: 'success', text: 'Teslim edildi olarak işaretlendi.' }

    const order = active.value

    await load()
    await open(order)
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'İşlem tamamlanamadı.',
    }
  } finally {
    busy.value = false
  }
}

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString('tr-TR')
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-xl font-medium">Kargo</h1>
      <p class="mt-1 text-sm text-muted">
        Bir sipariş birden fazla koliyle gidebilir. Hepsi çıktığında sipariş kendiliğinden
        kargoya verilmiş sayılır.
      </p>
    </header>

    <RcAlert v-if="banner" :tone="banner.tone">{{ banner.text }}</RcAlert>
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <p
      v-else-if="orders.length === 0"
      class="rounded-sm border border-line bg-surface p-8 text-center text-sm text-muted"
      data-testid="shipping-empty"
    >
      Kargolanacak sipariş yok.
    </p>

    <div v-else class="grid gap-6 lg:grid-cols-[320px_1fr]">
      <div class="overflow-hidden rounded-sm border border-line bg-surface">
        <button
          v-for="order in orders"
          :key="order.seller_order_number"
          type="button"
          class="flex w-full items-baseline justify-between gap-3 border-b border-line px-4 py-3 text-left last:border-0 hover:bg-bg-muted"
          :class="active?.seller_order_number === order.seller_order_number ? 'bg-bg-muted' : ''"
          data-testid="shipping-order"
          @click="open(order)"
        >
          <span class="font-mono text-xs">{{ order.seller_order_number }}</span>
          <span class="text-xs text-muted">{{ order.status_label }}</span>
        </button>
      </div>

      <div v-if="active" class="rounded-sm border border-line bg-surface p-6">
        <h2 class="font-mono text-sm">{{ active.seller_order_number }}</h2>

        <h3 class="mt-5 text-xs text-muted uppercase">Kolideki ürünler</h3>

        <p v-if="pending.length === 0" class="mt-2 text-sm text-muted" data-testid="nothing-to-ship">
          Bu siparişte gönderilecek ürün kalmadı.
        </p>

        <div v-else class="mt-2 space-y-3">
          <div
            v-for="line in pending"
            :key="line.order_item_id"
            class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-3 last:border-0"
            data-testid="pending-line"
          >
            <div class="min-w-0">
              <p class="text-sm">{{ line.product_name }}</p>
              <p class="text-xs text-muted">
                <span v-if="line.sku_code" class="font-mono">{{ line.sku_code }}</span>
                <span> · {{ line.ordered }} sipariş edildi, {{ line.remaining }} kaldı</span>
              </p>
            </div>

            <input
              v-model.number="quantities[line.order_item_id]"
              type="number"
              min="0"
              :max="line.remaining"
              class="w-20 rounded-sm border border-line bg-surface px-2 py-1 text-sm tabular-nums"
              :aria-label="`${line.product_name} adedi`"
            >
          </div>

          <div class="flex flex-wrap items-end gap-3 pt-2">
            <div class="min-w-[180px] flex-1">
              <label for="carrier" class="mb-1.5 block text-sm font-medium text-ink">Kargo firması</label>
              <input
                id="carrier"
                v-model="carrier"
                name="carrier"
                type="text"
                class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
                data-testid="carrier"
              >
            </div>

            <div class="min-w-[180px] flex-1">
              <label for="tracking" class="mb-1.5 block text-sm font-medium text-ink">Takip numarası</label>
              <input
                id="tracking"
                v-model="trackingNumber"
                name="tracking"
                type="text"
                class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
                data-testid="tracking"
              >
            </div>

            <RcButton :loading="busy" :disabled="chosen.length === 0" data-testid="ship" @click="ship">
              Kargoya ver
            </RcButton>
          </div>
        </div>

        <h3 class="mt-6 text-xs text-muted uppercase">Gönderilen koliler</h3>

        <p v-if="shipments.length === 0" class="mt-2 text-sm text-muted">Henüz koli yok.</p>

        <ul v-else class="mt-2 divide-y divide-line">
          <li
            v-for="shipment in shipments"
            :key="shipment.id"
            class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
            data-testid="shipment-row"
          >
            <div>
              <p>
                {{ shipment.carrier ?? 'Kargo firması belirtilmedi' }}
                <span v-if="shipment.tracking_number" class="font-mono text-xs text-muted">
                  · {{ shipment.tracking_number }}
                </span>
              </p>
              <p class="text-xs text-muted">
                {{ shipment.item_count }} ürün · {{ when(shipment.shipped_at) }}
              </p>
            </div>

            <!--
              Delivery is per parcel. The order follows the last one, because the return
              window starts when the box actually arrives.
            -->
            <RcButton
              v-if="shipment.delivered_at === null"
              variant="secondary"
              :loading="busy"
              @click="markDelivered(shipment)"
            >
              Teslim edildi
            </RcButton>

            <span v-else class="text-xs text-muted">
              Teslim: {{ when(shipment.delivered_at) }}
            </span>
          </li>
        </ul>
      </div>

      <p v-else class="rounded-sm border border-line bg-surface p-8 text-center text-sm text-muted">
        Soldan bir sipariş seçin.
      </p>
    </div>
  </div>
</template>
