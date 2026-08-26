<script setup lang="ts">
/**
 * The seller's orders.
 *
 * Opens on work to do rather than on an archive: a screen that starts by listing every
 * order ever placed is a screen nobody uses to actually pack anything.
 *
 * The next possible statuses come from the server rather than being hardcoded here. The
 * rules about which move is legal live in the status machine, and a second copy of them
 * in a Vue file is a second copy that drifts — and drifts silently, because the buttons
 * still render.
 */
import type { SellerOrderDetail, SellerOrderStatus, SellerOrderSummary } from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Siparişlerim' })

const api = useApi()

const orders = ref<SellerOrderSummary[]>([])
const active = ref<SellerOrderDetail | null>(null)

const loading = ref(true)
const busy = ref(false)
const loadError = ref<string | null>(null)
const banner = ref<{ tone: 'success' | 'danger', text: string } | null>(null)

const scope = ref<'open' | 'all'>('open')
const cancelReason = ref('')

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const response = await api.get<{ data: SellerOrderSummary[] }>(
      '/api/v1/seller/orders',
      scope.value === 'all' ? { scope: 'all' } : {},
    )

    orders.value = response.data
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Siparişler yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

watch(scope, load)

async function open(number: string) {
  banner.value = null
  cancelReason.value = ''

  try {
    const response = await api.get<{ data: SellerOrderDetail }>(`/api/v1/seller/orders/${number}`)
    active.value = response.data
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'Sipariş açılamadı.',
    }
  }
}

async function advance(status: SellerOrderStatus) {
  if (active.value === null) return

  busy.value = true
  banner.value = null

  try {
    const response = await api.post<{ data: SellerOrderDetail }>(
      `/api/v1/seller/orders/${active.value.seller_order_number}/status`,
      { status, reason: status === 'cancelled' ? cancelReason.value : undefined },
    )

    active.value = response.data
    banner.value = { tone: 'success', text: 'Sipariş güncellendi.' }
    await load()
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'Sipariş güncellenemedi.',
    }
  } finally {
    busy.value = false
  }
}

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString('tr-TR')
}

/** Cancelling is the one move that costs the customer something, so it is kept apart. */
const cancellable = computed(() =>
  active.value?.next_statuses.some(option => option.value === 'cancelled') ?? false,
)

const forwardMoves = computed(() =>
  active.value?.next_statuses.filter(option => option.value !== 'cancelled') ?? [],
)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-baseline justify-between gap-3">
      <div>
        <h1 class="text-xl font-medium">Siparişlerim</h1>
        <p class="mt-1 text-sm text-muted">
          Onaylayın, hazırlayın, kargoya verin. Kargoya verilen bir sipariş iptal edilemez.
        </p>
      </div>

      <div class="flex gap-2">
        <button
          v-for="option in [{ value: 'open', label: 'Bekleyenler' }, { value: 'all', label: 'Tümü' }]"
          :key="option.value"
          type="button"
          class="rounded-pill border px-3 py-1 text-sm"
          :class="scope === option.value ? 'border-ink bg-ink text-surface' : 'border-line hover:bg-bg-muted'"
          @click="scope = option.value as 'open' | 'all'"
        >
          {{ option.label }}
        </button>
      </div>
    </header>

    <RcAlert v-if="banner" :tone="banner.tone">{{ banner.text }}</RcAlert>
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <p v-else-if="orders.length === 0" class="rounded-sm border border-line bg-surface p-10 text-center text-sm text-muted">
      Bu listede sipariş yok.
    </p>

    <div v-else class="overflow-x-auto rounded-sm border border-line bg-surface">
      <table class="w-full text-sm">
        <thead class="border-b border-line text-left text-xs text-muted uppercase">
          <tr>
            <th class="px-4 py-3">Sipariş</th>
            <th class="px-4 py-3">Tarih</th>
            <th class="px-4 py-3 text-right">Ürün</th>
            <th class="px-4 py-3 text-right">Tutar</th>
            <th class="px-4 py-3 text-right">Hakediş</th>
            <th class="px-4 py-3">Durum</th>
            <th class="px-4 py-3" />
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="row in orders" :key="row.id">
            <td class="px-4 py-3 font-mono text-xs">{{ row.seller_order_number }}</td>
            <td class="px-4 py-3 text-xs text-muted">{{ when(row.placed_at) }}</td>
            <td class="px-4 py-3 text-right tabular-nums">{{ row.item_count }}</td>
            <td class="px-4 py-3 text-right tabular-nums">{{ money(row.total_minor, row.currency) }}</td>
            <!--
              What the seller actually receives, next to what the customer paid. Showing
              only the gross is how a payout becomes a surprise.
            -->
            <td class="px-4 py-3 text-right tabular-nums">{{ money(row.payable_minor, row.currency) }}</td>
            <td class="px-4 py-3">
              <span class="rounded-pill bg-bg-muted px-2 py-0.5 text-xs">{{ row.status_label }}</span>
            </td>
            <td class="px-4 py-3 text-right">
              <RcButton size="sm" variant="ghost" @click="open(row.seller_order_number)">Aç</RcButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <section v-if="active" class="rounded-sm border border-line-strong bg-surface p-6">
      <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h2 class="font-mono text-sm">{{ active.seller_order_number }}</h2>
        <span class="rounded-pill bg-bg-muted px-3 py-1 text-xs">{{ active.status_label }}</span>
      </div>

      <div class="mt-5 grid gap-6 sm:grid-cols-2">
        <div>
          <h3 class="text-xs text-muted uppercase">Teslimat</h3>
          <p class="mt-2 text-sm">
            <span class="font-medium">{{ active.recipient.name }}</span><br>
            <span class="text-ink-secondary">
              {{ [active.recipient.address_line1, active.recipient.district, active.recipient.city]
                .filter(Boolean).join(', ') }}
            </span><br>
            <span v-if="active.recipient.phone" class="text-ink-secondary">{{ active.recipient.phone }}</span>
          </p>
        </div>

        <div>
          <h3 class="text-xs text-muted uppercase">Tutar</h3>
          <dl class="mt-2 space-y-1 text-sm">
            <div class="flex justify-between gap-4">
              <dt class="text-ink-secondary">Sipariş toplamı</dt>
              <dd class="tabular-nums">{{ money(active.total_minor, active.currency) }}</dd>
            </div>
            <div class="flex justify-between gap-4 text-muted">
              <dt>Komisyon</dt>
              <dd class="tabular-nums">−{{ money(active.commission_minor, active.currency) }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-t border-line pt-1 font-medium">
              <dt>Hakedişiniz</dt>
              <dd class="tabular-nums">{{ money(active.payable_minor, active.currency) }}</dd>
            </div>
          </dl>
        </div>
      </div>

      <h3 class="mt-6 text-xs text-muted uppercase">Ürünler</h3>
      <ul class="mt-2 divide-y divide-line">
        <li v-for="item in active.items" :key="item.id" class="flex justify-between gap-4 py-3 text-sm">
          <div>
            <p>{{ item.product_name }}</p>
            <p class="text-xs text-muted">
              <span v-if="item.sku_code" class="font-mono">{{ item.sku_code }}</span>
              <span v-if="item.variant_label"> · {{ item.variant_label }}</span>
            </p>
          </div>
          <p class="shrink-0 tabular-nums">{{ item.quantity }} × {{ money(item.unit_price_minor, active.currency) }}</p>
        </li>
      </ul>

      <div v-if="active.next_statuses.length > 0" class="mt-6 flex flex-wrap gap-3">
        <RcButton
          v-for="option in forwardMoves"
          :key="option.value"
          :loading="busy"
          @click="advance(option.value)"
        >
          {{ option.label }} olarak işaretle
        </RcButton>
      </div>

      <p v-else class="mt-6 text-sm text-muted">Bu sipariş için yapılacak bir işlem kalmadı.</p>

      <!-- Cancelling is kept apart from the forward moves, and needs a reason. -->
      <div v-if="cancellable" class="mt-6 border-t border-line pt-5">
        <label for="cancel-reason" class="mb-1.5 block text-sm font-medium">İptal gerekçesi</label>
        <input
          id="cancel-reason"
          v-model="cancelReason"
          type="text"
          maxlength="300"
          placeholder="Depoda hasar bulundu"
          class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
        >
        <RcButton
          class="mt-3"
          variant="danger"
          :loading="busy"
          :disabled="cancelReason.trim().length < 3"
          @click="advance('cancelled')"
        >
          Siparişi iptal et
        </RcButton>
      </div>

      <p v-if="active.cancellation_reason" class="mt-4 rounded-sm bg-bg-muted px-3 py-2 text-sm text-ink-secondary">
        İptal gerekçesi: {{ active.cancellation_reason }}
      </p>
    </section>
  </div>
</template>
