<script setup lang="ts">
/**
 * What the seller is owed, and when they will get it.
 *
 * The balance is split four ways rather than shown as one figure, because the money is
 * genuinely in four states and collapsing them is how a seller reads a number they cannot
 * yet have. And every order carries a sentence rather than a status: "12.09.2026 tarihinde
 * hakedişe girer" is something a seller can plan around, and "pending" is not.
 */
import type { SellerEarningsOrder, SellerEarningsSummary, SettlementRow } from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Hakedişlerim' })

const api = useApi()

const summary = ref<SellerEarningsSummary | null>(null)
const orders = ref<SellerEarningsOrder[]>([])
const settlements = ref<SettlementRow[]>([])
const loadError = ref<string | null>(null)

try {
  const [balance, orderList, settlementList] = await Promise.all([
    api.get<{ data: SellerEarningsSummary }>('/api/v1/seller/earnings'),
    api.get<{ data: SellerEarningsOrder[] }>('/api/v1/seller/earnings/orders'),
    api.get<{ data: SettlementRow[] }>('/api/v1/seller/earnings/settlements'),
  ])

  summary.value = balance.data
  orders.value = orderList.data
  settlements.value = settlementList.data
} catch (error) {
  loadError.value = error instanceof ApiError ? error.message : 'Hakediş bilgileri yüklenemedi.'
}

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString('tr-TR')
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-xl font-medium">Hakedişlerim</h1>
      <p v-if="summary" class="mt-1 text-sm text-muted">
        Teslimattan {{ summary.hold_days }} gün sonra ödemeye hazır hâle gelir.
      </p>
    </header>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <template v-else-if="summary">
      <!--
        Four figures, not one. The money really is in four states, and a single "bakiye"
        is how a seller reads a number they cannot yet have.
      -->
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-sm border border-line bg-surface p-5">
          <p class="text-[11px] tracking-wide text-muted uppercase">Ödemeye hazır</p>
          <p class="mt-1 text-2xl font-medium tabular-nums">
            {{ money(summary.available_minor, summary.currency) }}
          </p>
        </div>

        <div class="rounded-sm border border-line bg-surface p-5">
          <p class="text-[11px] tracking-wide text-muted uppercase">Bekleyen</p>
          <p class="mt-1 text-2xl font-medium tabular-nums">
            {{ money(summary.pending_minor, summary.currency) }}
          </p>
          <p class="mt-1 text-xs text-muted">Teslimat veya bekleme süresi tamamlanmadı</p>
        </div>

        <div class="rounded-sm border border-line bg-surface p-5">
          <p class="text-[11px] tracking-wide text-muted uppercase">Ödeme sırasında</p>
          <p class="mt-1 text-2xl font-medium tabular-nums">
            {{ money(summary.reserved_minor, summary.currency) }}
          </p>
          <p class="mt-1 text-xs text-muted">Onaylanmış, transferi bekleniyor</p>
        </div>

        <div class="rounded-sm border border-line bg-surface p-5">
          <p class="text-[11px] tracking-wide text-muted uppercase">Ödenen</p>
          <p class="mt-1 text-2xl font-medium tabular-nums">
            {{ money(summary.paid_out_minor, summary.currency) }}
          </p>
        </div>
      </div>

      <section v-if="settlements.length > 0">
        <h2 class="text-sm font-medium">Hakediş dönemleri</h2>

        <div class="mt-3 overflow-x-auto rounded-sm border border-line bg-surface">
          <table class="w-full text-sm">
            <thead class="border-b border-line text-left text-xs text-muted uppercase">
              <tr>
                <th class="px-4 py-3">Referans</th>
                <th class="px-4 py-3">Dönem</th>
                <th class="px-4 py-3 text-right">Sipariş</th>
                <th class="px-4 py-3 text-right">Komisyon</th>
                <th class="px-4 py-3 text-right">Net</th>
                <th class="px-4 py-3">Durum</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="row in settlements" :key="row.id">
                <td class="px-4 py-3 font-mono text-xs">{{ row.reference }}</td>
                <td class="px-4 py-3 text-xs text-muted">
                  {{ when(row.period_start) }} – {{ when(row.period_end) }}
                </td>
                <td class="px-4 py-3 text-right tabular-nums">{{ row.item_count }}</td>
                <td class="px-4 py-3 text-right tabular-nums text-muted">
                  −{{ money(row.commission_minor, row.currency) }}
                </td>
                <td class="px-4 py-3 text-right font-medium tabular-nums">
                  {{ money(row.net_minor, row.currency) }}
                </td>
                <td class="px-4 py-3">
                  <span
                    class="rounded-pill px-2 py-0.5 text-xs"
                    :class="row.status === 'paid'
                      ? 'bg-success-subtle text-success-strong'
                      : 'bg-bg-muted text-ink-secondary'"
                  >
                    {{ row.status_label }}
                  </span>
                  <span v-if="row.payout_reference" class="ml-2 font-mono text-xs text-muted">
                    {{ row.payout_reference }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section>
        <h2 class="text-sm font-medium">Siparişler</h2>

        <p v-if="orders.length === 0" class="mt-3 rounded-sm border border-line bg-surface p-8 text-center text-sm text-muted">
          Henüz hakedişe konu sipariş yok.
        </p>

        <div v-else class="mt-3 overflow-x-auto rounded-sm border border-line bg-surface">
          <table class="w-full text-sm">
            <thead class="border-b border-line text-left text-xs text-muted uppercase">
              <tr>
                <th class="px-4 py-3">Sipariş</th>
                <th class="px-4 py-3 text-right">Tutar</th>
                <th class="px-4 py-3 text-right">Komisyon</th>
                <th class="px-4 py-3 text-right">Hakediş</th>
                <th class="px-4 py-3">Durum</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="row in orders" :key="row.seller_order_number">
                <td class="px-4 py-3 font-mono text-xs">{{ row.seller_order_number }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ money(row.total_minor) }}</td>
                <td class="px-4 py-3 text-right tabular-nums text-muted">
                  −{{ money(row.commission_minor) }}
                </td>
                <td class="px-4 py-3 text-right font-medium tabular-nums">
                  {{ money(row.payable_minor) }}
                </td>
                <td class="px-4 py-3 text-xs text-ink-secondary">
                  <!-- The sentence. Sellers ask, and silence is a support ticket. -->
                  {{ row.settlement_note }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>
