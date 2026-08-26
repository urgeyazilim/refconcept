<script setup lang="ts">
/**
 * The platform at a glance — and, more usefully, the queue.
 *
 * Two halves, deliberately not mixed. The top is what happened: orders, money, the size
 * of the marketplace. The bottom is what is still waiting for a person to decide, and it
 * is the half somebody actually opens this page for. A dashboard that folds a pending
 * refund into an average order value has hidden the only number that needed acting on.
 *
 * The money figures come from the ledger rather than from the orders. The two disagreeing
 * is exactly what a dashboard exists to surface, so reading both from the same place
 * would defeat the point.
 */
import type { AdminOverview, AdminOrderSeriesPoint } from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Gösterge paneli' })

const api = useApi()

const overview = ref<AdminOverview | null>(null)
const series = ref<AdminOrderSeriesPoint[]>([])
const days = ref(30)
const loading = ref(true)
const loadError = ref<string | null>(null)

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const [summary, points] = await Promise.all([
      api.get<{ data: AdminOverview }>('/api/v1/admin/analytics/overview', { days: days.value }),
      api.get<{ data: AdminOrderSeriesPoint[] }>('/api/v1/admin/analytics/orders', { days: days.value }),
    ])

    overview.value = summary.data
    series.value = points.data
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Gösterge paneli yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

watch(days, load)

function money(minor: number): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' }).format(minor / 100)
}

/** The queue, in the order somebody should work it: money first, then goods, then plumbing. */
const queue = computed(() => {
  const waiting = overview.value?.waiting

  if (!waiting) {
    return []
  }

  return [
    { key: 'transfers', label: 'Kontrol bekleyen havale', count: waiting.transfers_to_check, to: '/payments' },
    { key: 'settlements', label: 'Açık hakediş', count: waiting.settlements_open, to: '/finance' },
    { key: 'refunds', label: 'Başarısız iade ödemesi', count: waiting.failed_refunds, to: '/finance' },
    { key: 'seller-orders', label: 'Onaylanmamış satıcı siparişi', count: waiting.seller_orders_unconfirmed, to: '/orders' },
    { key: 'returns', label: 'Açık iade talebi', count: waiting.open_returns, to: '/orders' },
    { key: 'jobs', label: 'Başarısız kuyruk işi', count: waiting.failed_jobs, to: '/system' },
  ]
})

/** Bars are drawn against the busiest day, so a quiet week still has shape. */
const peak = computed(() => Math.max(1, ...series.value.map(point => point.orders)))

function shortDay(day: string): string {
  return new Date(day).toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit' })
}

function barHeight(orders: number): string {
  return String(Math.round((orders / peak.value) * 96) + 2) + 'px'
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-baseline justify-between gap-3">
      <div>
        <h1 class="text-xl font-medium">Gösterge paneli</h1>
        <p class="mt-1 text-sm text-muted">Platformun son {{ days }} günü ve bekleyen işler.</p>
      </div>

      <div class="flex gap-2">
        <button
          v-for="option in [7, 30, 90]"
          :key="option"
          type="button"
          class="rounded-pill border px-3 py-1 text-sm"
          :class="days === option ? 'border-ink bg-ink text-surface' : 'border-line hover:bg-bg-muted'"
          @click="days = option"
        >
          {{ option }} gün
        </button>
      </div>
    </header>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <template v-else-if="overview">
      <!-- Same rule as the finance page: an unbalanced journal stops everything else. -->
      <RcAlert v-if="!overview.money.is_balanced" tone="danger">
        <strong>Defter denk değil.</strong> Aşağıdaki tutarlar güvenilir değil; teknik ekiple görüşün.
      </RcAlert>

      <section aria-label="Bekleyen işler">
        <h2 class="text-sm font-medium">Sizi bekleyenler</h2>

        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <NuxtLink
            v-for="item in queue"
            :key="item.key"
            :to="item.to"
            class="flex items-baseline justify-between gap-3 rounded-sm border bg-surface p-4 transition-colors hover:bg-bg-muted"
            :class="item.count > 0 ? 'border-line-strong' : 'border-line'"
            :data-testid="'queue-' + item.key"
          >
            <span class="text-sm text-ink-secondary">{{ item.label }}</span>
            <span
              class="text-lg font-medium tabular-nums"
              :class="item.count > 0 ? 'text-ink' : 'text-muted'"
            >{{ item.count }}</span>
          </NuxtLink>
        </div>
      </section>

      <section aria-label="Özet">
        <h2 class="text-sm font-medium">Son {{ overview.period_days }} gün</h2>

        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div class="rounded-sm border border-line bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">Sipariş</p>
            <p class="mt-1 text-xl font-medium tabular-nums" data-testid="stat-orders">
              {{ overview.orders.count }}
            </p>
            <p class="mt-1 text-[11px] text-muted">Ortalama {{ money(overview.orders.average_minor) }}</p>
          </div>

          <div class="rounded-sm border border-line bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">Ciro</p>
            <p class="mt-1 text-xl font-medium tabular-nums">{{ money(overview.orders.gross_minor) }}</p>
            <p class="mt-1 text-[11px] text-muted">Sipariş tutarları toplamı</p>
          </div>

          <div class="rounded-sm border border-line bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">Komisyon</p>
            <p class="mt-1 text-xl font-medium tabular-nums">{{ money(overview.money.commission_minor) }}</p>
            <p class="mt-1 text-[11px] text-muted">Defterden</p>
          </div>

          <div class="rounded-sm border border-line bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">Satıcıya borç</p>
            <p class="mt-1 text-xl font-medium tabular-nums">{{ money(overview.money.sellers_owed_minor) }}</p>
            <p class="mt-1 text-[11px] text-muted">Kasada {{ money(overview.money.cash_minor) }}</p>
          </div>
        </div>
      </section>

      <section aria-label="Pazar yeri">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div class="rounded-sm border border-line bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">Aktif satıcı</p>
            <p class="mt-1 text-xl font-medium tabular-nums">{{ overview.marketplace.active_sellers }}</p>
          </div>
          <div class="rounded-sm border border-line bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">Yayındaki ürün</p>
            <p class="mt-1 text-xl font-medium tabular-nums">{{ overview.marketplace.live_products }}</p>
            <p class="mt-1 text-[11px] text-muted">{{ overview.marketplace.pending_moderation }} ürün onay bekliyor</p>
          </div>
          <div class="rounded-sm border border-line bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">Yeni müşteri</p>
            <p class="mt-1 text-xl font-medium tabular-nums">{{ overview.marketplace.new_customers }}</p>
          </div>
          <div class="rounded-sm border border-line bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">AI işi</p>
            <p class="mt-1 text-xl font-medium tabular-nums">{{ overview.ai.jobs }}</p>
            <p class="mt-1 text-[11px] text-muted">{{ overview.ai.failed }} tanesi başarısız</p>
          </div>
        </div>
      </section>

      <section aria-label="Günlük sipariş">
        <h2 class="text-sm font-medium">Günlük sipariş</h2>

        <p v-if="series.length === 0" class="mt-3 rounded-sm border border-line bg-surface p-8 text-center text-sm text-muted">
          Bu dönemde sipariş yok.
        </p>

        <!--
          Bars rather than a chart library. The question here is "did anything stop", and
          a row of heights answers it without a dependency.
        -->
        <div v-else class="mt-3 flex items-end gap-1 overflow-x-auto rounded-sm border border-line bg-surface p-5">
          <div
            v-for="point in series"
            :key="point.day"
            class="flex min-w-[24px] flex-1 flex-col items-center gap-1"
            :title="point.day + ': ' + point.orders + ' sipariş'"
          >
            <span class="text-[10px] text-muted tabular-nums">{{ point.orders }}</span>
            <div class="w-full rounded-t-sm bg-charcoal" :style="{ height: barHeight(point.orders) }" />
            <span class="text-[10px] text-muted">{{ shortDay(point.day) }}</span>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
