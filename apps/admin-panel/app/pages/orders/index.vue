<script setup lang="ts">
/**
 * Every order on the platform, for the person answering the phone.
 *
 * Searched by order number or e-mail address, because those are the two things a caller
 * actually has. Read-only: an operator changing an order's state from here would bypass
 * the transitions the seller portal enforces, and "support moved it" is the hardest kind
 * of history to reconstruct afterwards.
 */
import type { AdminOrderRow } from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Siparişler' })

const api = useApi()

const orders = ref<AdminOrderRow[]>([])
const search = ref('')
const status = ref('')
const loading = ref(true)
const loadError = ref<string | null>(null)

const statuses = [
  { value: '', label: 'Tümü' },
  { value: 'pending', label: 'Bekliyor' },
  { value: 'paid', label: 'Ödendi' },
  { value: 'processing', label: 'Hazırlanıyor' },
  { value: 'shipped', label: 'Kargoda' },
  { value: 'delivered', label: 'Teslim edildi' },
  { value: 'cancelled', label: 'İptal' },
]

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const params: Record<string, string> = {}

    if (search.value.trim() !== '') {
      params.search = search.value.trim()
    }

    if (status.value !== '') {
      params.status = status.value
    }

    const response = await api.get<{ data: AdminOrderRow[] }>('/api/v1/admin/orders', params)

    orders.value = response.data
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Siparişler yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

watch(status, load)

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString('tr-TR')
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-xl font-medium">Siparişler</h1>
      <p class="mt-1 text-sm text-muted">
        Sipariş numarası ya da müşteri e-postası ile arayın. Bu ekran salt okunurdur.
      </p>
    </header>

    <form class="flex flex-wrap items-end gap-3" @submit.prevent="load">
      <div class="min-w-[260px] flex-1">
        <label for="order-search" class="mb-1.5 block text-sm font-medium text-ink">Ara</label>
        <input
          id="order-search"
          v-model="search"
          name="order-search"
          type="search"
          placeholder="RC-2026-000123 veya ornek@eposta.com"
          class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
          data-testid="order-search"
        >
      </div>

      <div class="w-48">
        <label for="order-status" class="mb-1.5 block text-sm font-medium text-ink">Durum</label>
        <select
          id="order-status"
          v-model="status"
          name="order-status"
          class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
        >
          <option v-for="option in statuses" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </div>

      <RcButton type="submit" :loading="loading">Ara</RcButton>
    </form>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <p
      v-else-if="orders.length === 0"
      class="rounded-sm border border-line bg-surface p-8 text-center text-sm text-muted"
      data-testid="orders-empty"
    >
      Bu ölçütlere uyan sipariş yok.
    </p>

    <div v-else class="overflow-x-auto rounded-sm border border-line bg-surface">
      <table class="w-full text-sm">
        <thead class="border-b border-line text-left text-xs text-muted uppercase">
          <tr>
            <th class="px-4 py-3">Sipariş</th>
            <th class="px-4 py-3">Müşteri</th>
            <th class="px-4 py-3">Durum</th>
            <th class="px-4 py-3 text-right">Satıcı</th>
            <th class="px-4 py-3 text-right">Tutar</th>
            <th class="px-4 py-3">Tarih</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="order in orders"
            :key="order.order_number"
            class="border-b border-line last:border-0"
            data-testid="order-row"
          >
            <td class="px-4 py-3 font-mono text-xs">{{ order.order_number }}</td>
            <td class="px-4 py-3">{{ order.customer_email ?? '—' }}</td>
            <td class="px-4 py-3">
              <RcStatusPill :status="order.status" :label="order.status_label" size="sm" />
            </td>
            <td class="px-4 py-3 text-right tabular-nums">{{ order.seller_count }}</td>
            <td class="px-4 py-3 text-right tabular-nums">
              {{ money(order.totals.grand_total_minor, order.currency) }}
            </td>
            <td class="px-4 py-3 text-xs text-muted">{{ when(order.placed_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
