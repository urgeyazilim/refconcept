<script setup lang="ts">
/**
 * The customer's orders.
 *
 * One row per order, not per parcel. A customer who paid once thinks of it as one
 * purchase, and splitting the list by seller would show them three orders they never
 * placed — the split matters to warehouses, not to the person who bought the things.
 */
import type { OrderSummary } from '@refconcept/ui/types'

definePageMeta({ layout: 'account', middleware: ['auth', 'verified'] })
useSeo({ title: 'Siparişlerim', noindex: true })

const api = useApi()

const orders = ref<OrderSummary[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

try {
  const response = await api.get<{ data: OrderSummary[] }>('/api/v1/orders')
  orders.value = response.data
} catch (error) {
  loadError.value = error instanceof ApiError ? error.message : 'Siparişler yüklenemedi.'
} finally {
  loading.value = false
}

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}

function when(value: string): string {
  return new Date(value).toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' })
}

const tone = (status: string): string => {
  switch (status) {
    case 'delivered':
      return 'bg-success-subtle text-success-strong'
    case 'cancelled':
    case 'refunded':
      return 'bg-bg-muted text-ink-secondary'
    case 'shipped':
    case 'partially_shipped':
      return 'bg-info-subtle text-info-strong'
    default:
      return 'bg-bg-muted text-ink-secondary'
  }
}
</script>

<template>
  <div>
    <h1 class="text-xl font-medium">Siparişlerim</h1>

    <RcAlert v-if="loadError" tone="danger" class="mt-6">{{ loadError }}</RcAlert>

    <p v-else-if="loading" class="mt-6 text-sm text-muted">Yükleniyor…</p>

    <div v-else-if="orders.length === 0" class="rc-card mt-6 p-10 text-center">
      <p class="text-sm text-muted">Henüz siparişiniz yok.</p>
      <RcButton class="mt-4" to="/catalog">Ürünlere göz atın</RcButton>
    </div>

    <ul v-else class="mt-6 space-y-3">
      <li v-for="order in orders" :key="order.id">
        <NuxtLink
          :to="`/account/orders/${order.order_number}`"
          class="rc-card flex flex-wrap items-center justify-between gap-4 p-5 hover:border-line-strong"
        >
          <div>
            <p class="font-mono text-sm">{{ order.order_number }}</p>
            <p class="mt-1 text-xs text-muted">
              {{ when(order.placed_at) }} · {{ order.item_count }} ürün
            </p>
          </div>

          <div class="flex items-center gap-4">
            <span class="rounded-pill px-3 py-1 text-xs" :class="tone(order.status)">
              {{ order.status_label }}
            </span>
            <span class="text-sm font-medium tabular-nums">
              {{ money(order.totals.grand_total_minor, order.currency) }}
            </span>
          </div>
        </NuxtLink>
      </li>
    </ul>
  </div>
</template>
