<script setup lang="ts">
/**
 * One order, shown the way it will actually arrive.
 *
 * Grouped by seller with a status on each group, because that is the truth about a
 * marketplace order: three parcels from three shops on three different days. A single
 * status across the whole thing would be right only on the day the last one lands.
 */
import type { OrderDetail } from '@refconcept/ui/types'

definePageMeta({ layout: 'account', middleware: ['auth', 'verified'] })

const api = useApi()
const route = useRoute()

const orderNumber = computed(() => String(route.params.number ?? ''))

const order = ref<OrderDetail | null>(null)
const loadError = ref<string | null>(null)

try {
  const response = await api.get<{ data: OrderDetail }>(`/api/v1/orders/${orderNumber.value}`)
  order.value = response.data
} catch (error) {
  loadError.value = error instanceof ApiError ? error.message : 'Sipariş yüklenemedi.'
}

useHead({ title: () => (order.value ? `Sipariş ${order.value.order_number}` : 'Sipariş') })

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}

function when(value: string | null): string {
  return value === null ? '' : new Date(value).toLocaleString('tr-TR')
}

const address = computed(() => {
  const value = order.value?.shipping_address

  if (!value) return ''

  return [value.address_line1, value.district, value.city].filter(Boolean).join(', ')
})
</script>

<template>
  <div>
    <RcAlert v-if="loadError" tone="danger">
      {{ loadError }}
      <NuxtLink to="/account/orders" class="ml-1 underline">Siparişlerime dön</NuxtLink>
    </RcAlert>

    <template v-else-if="order">
      <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
          <h1 class="font-mono text-xl">{{ order.order_number }}</h1>
          <p class="mt-1 text-sm text-muted">{{ when(order.placed_at) }}</p>
        </div>
        <span class="rounded-pill bg-bg-muted px-3 py-1 text-sm">{{ order.status_label }}</span>
      </div>

      <!-- One block per seller: several parcels, several shops, several days. -->
      <section v-for="group in order.sellers" :key="group.id" class="rc-card mt-6 p-5 sm:p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
          <div>
            <h2 class="text-sm font-medium">{{ group.seller_name ?? 'Satıcı' }}</h2>
            <p class="mt-0.5 font-mono text-xs text-muted">{{ group.seller_order_number }}</p>
          </div>
          <div class="text-right">
            <span class="rounded-pill bg-bg-muted px-3 py-1 text-xs">{{ group.status_label }}</span>
            <p v-if="group.shipped_at" class="mt-1 text-xs text-muted">
              {{ when(group.shipped_at) }} tarihinde kargoya verildi
            </p>
          </div>
        </div>

        <ul class="mt-4 divide-y divide-line">
          <li v-for="item in group.items" :key="item.id" class="flex gap-4 py-4">
            <div class="size-16 shrink-0 overflow-hidden rounded-sm bg-bg-muted">
              <img
                v-if="item.image_url"
                :src="item.image_url"
                :alt="item.product_name"
                class="size-full object-cover"
                loading="lazy"
              >
            </div>

            <div class="min-w-0 flex-1">
              <p class="text-sm">{{ item.product_name }}</p>
              <p v-if="item.variant_label" class="text-xs text-muted">{{ item.variant_label }}</p>
              <p class="mt-1 text-xs text-muted">{{ item.quantity }} adet</p>
            </div>

            <p class="shrink-0 text-sm tabular-nums">
              {{ money(item.line_total_minor, order.currency) }}
            </p>
          </li>
        </ul>
      </section>

      <div class="mt-6 grid gap-6 sm:grid-cols-2">
        <section class="rc-card p-5">
          <h2 class="text-sm font-medium">Teslimat adresi</h2>
          <p class="mt-2 text-sm">
            <span class="font-medium">{{ order.shipping_address?.recipient_name }}</span><br>
            <span class="text-ink-secondary">{{ address }}</span>
          </p>
        </section>

        <section class="rc-card p-5">
          <h2 class="text-sm font-medium">Özet</h2>
          <dl class="mt-3 space-y-2 text-sm">
            <div class="flex justify-between gap-4">
              <dt class="text-ink-secondary">Ara toplam</dt>
              <dd class="tabular-nums">{{ money(order.totals.subtotal_minor, order.currency) }}</dd>
            </div>
            <div v-if="order.totals.shipping_minor > 0" class="flex justify-between gap-4">
              <dt class="text-ink-secondary">Kargo</dt>
              <dd class="tabular-nums">{{ money(order.totals.shipping_minor, order.currency) }}</dd>
            </div>
            <!-- KDV is contained in the price in Turkey, not added to it. -->
            <div class="flex justify-between gap-4 text-muted">
              <dt>Dâhil KDV</dt>
              <dd class="tabular-nums">{{ money(order.totals.tax_minor, order.currency) }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-t border-line pt-2 font-medium">
              <dt>Toplam</dt>
              <dd class="tabular-nums">{{ money(order.totals.grand_total_minor, order.currency) }}</dd>
            </div>
          </dl>
        </section>
      </div>

      <RcButton class="mt-6" variant="ghost" to="/account/orders">Siparişlerime dön</RcButton>
    </template>
  </div>
</template>
