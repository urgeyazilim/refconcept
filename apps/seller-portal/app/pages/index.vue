<script setup lang="ts">
/**
 * The seller's own front page, and it answers a different question depending on who is
 * looking.
 *
 * Before approval there is exactly one thing worth saying: where is my application and what
 * do I do next. After approval that question is answered and a different one takes over —
 * **what is waiting for me** — so the page swaps rather than accumulating a status banner
 * nobody needs to read again.
 *
 * The queue comes before the money. A seller already knows roughly what they sold; what
 * they do not know is that four orders have been sitting unconfirmed since Friday, and a
 * dashboard that leads with a revenue figure has hidden the only part that needed acting
 * on this morning.
 */
import type { SellerDashboard } from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Satıcı Paneli' })

const api = useApi()
const { displayName } = useAuth()

interface ApplicationSummary {
  id: string
  company_name: string
  display_name: string
  status: string
  status_label: string
  decision_reason: string | null
  submitted_at: string | null
}

const application = ref<ApplicationSummary | null>(null)
const completion = ref(0)
const dashboard = ref<SellerDashboard | null>(null)
const loadError = ref<string | null>(null)

try {
  const response = await api.get<{ data: ApplicationSummary | null, meta?: { completion_percent: number } }>(
    '/api/v1/seller/application',
  )

  application.value = response.data
  completion.value = response.meta?.completion_percent ?? 0
} catch (error) {
  loadError.value = error instanceof ApiError ? error.message : 'Başvuru durumu okunamadı.'
}

/*
 * Asked unconditionally rather than only for an approved application.
 *
 * A colleague added to the team has no application of their own — theirs belongs to the
 * owner — so keying the dashboard off the application would show every member of staff an
 * invitation to start applying.
 */
try {
  const response = await api.get<{ data: SellerDashboard }>('/api/v1/seller/dashboard')

  dashboard.value = response.data
} catch {
  // No seller behind this account yet. That is the ordinary case for an applicant, and
  // the application panels below are the right answer for them.
  dashboard.value = null
}

const state = computed(() => {
  if (application.value === null) return 'none'

  return application.value.status
})

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}

/** The work, in the order somebody clears it: customers first, then the shelves. */
const queue = computed(() => {
  const waiting = dashboard.value?.waiting

  if (!waiting) {
    return []
  }

  return [
    { key: 'unconfirmed', label: 'Onay bekleyen sipariş', count: waiting.unconfirmed_orders, to: '/orders' },
    { key: 'to-ship', label: 'Kargolanacak sipariş', count: waiting.to_ship, to: '/shipping' },
    { key: 'returns', label: 'Açık iade talebi', count: waiting.open_returns, to: '/returns' },
    { key: 'low-stock', label: 'Stoğu azalan ürün', count: waiting.low_stock, to: '/stock' },
    { key: 'moderation', label: 'İnceleme bekleyen ürün', count: waiting.pending_moderation, to: '/products' },
  ]
})
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6">
    <header>
      <h1 class="text-2xl font-medium">Hoş geldiniz{{ displayName ? `, ${displayName}` : '' }}</h1>
    </header>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <!--
      An approved seller gets the work, not a status. The application panels below are for
      somebody who does not have a seller account yet.
    -->
    <template v-if="dashboard">
      <section aria-label="Bekleyen işler">
        <h2 class="text-sm font-medium">Sizi bekleyenler</h2>

        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <NuxtLink
            v-for="item in queue"
            :key="item.key"
            :to="item.to"
            class="flex items-baseline justify-between gap-3 rounded-sm border bg-surface p-4 transition-colors hover:bg-bg-muted"
            :class="item.count > 0 ? 'border-line-strong' : 'border-line'"
            :data-testid="'seller-queue-' + item.key"
          >
            <span class="text-sm text-ink-secondary">{{ item.label }}</span>
            <span
              class="text-lg font-medium tabular-nums"
              :class="item.count > 0 ? 'text-ink' : 'text-muted'"
            >{{ item.count }}</span>
          </NuxtLink>
        </div>
      </section>

      <section aria-label="Hakedişler">
        <h2 class="text-sm font-medium">Paranız</h2>

        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div class="rounded-sm border border-line-strong bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">Ödemeye hazır</p>
            <p class="mt-1 text-xl font-medium tabular-nums">
              {{ money(dashboard.earnings.available_minor, dashboard.earnings.currency) }}
            </p>
          </div>
          <div class="rounded-sm border border-line bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">Bekleyen</p>
            <p class="mt-1 text-xl font-medium tabular-nums">
              {{ money(dashboard.earnings.pending_minor, dashboard.earnings.currency) }}
            </p>
          </div>
          <div class="rounded-sm border border-line bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">Ödeme sırasında</p>
            <p class="mt-1 text-xl font-medium tabular-nums">
              {{ money(dashboard.earnings.in_settlement_minor, dashboard.earnings.currency) }}
            </p>
          </div>
          <div class="rounded-sm border border-line bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">Ödenen</p>
            <p class="mt-1 text-xl font-medium tabular-nums">
              {{ money(dashboard.earnings.paid_minor, dashboard.earnings.currency) }}
            </p>
          </div>
        </div>

        <p class="mt-2 text-xs text-muted">
          Son {{ dashboard.sales.period_days }} günde {{ dashboard.sales.orders }} sipariş ·
          {{ money(dashboard.sales.gross_minor) }} ciro ·
          komisyon sonrası {{ money(dashboard.sales.payable_minor) }}
        </p>
      </section>

      <section aria-label="Katalog">
        <h2 class="text-sm font-medium">Kataloğunuz</h2>

        <div class="mt-3 grid gap-3 sm:grid-cols-3">
          <div class="rounded-sm border border-line bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">Yayında</p>
            <p class="mt-1 text-xl font-medium tabular-nums">{{ dashboard.catalogue.live }}</p>
          </div>
          <div class="rounded-sm border border-line bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">Taslak</p>
            <p class="mt-1 text-xl font-medium tabular-nums">{{ dashboard.catalogue.draft }}</p>
          </div>
          <div class="rounded-sm border border-line bg-surface p-5">
            <p class="text-[11px] tracking-wide text-muted uppercase">Stoğu biten</p>
            <p class="mt-1 text-xl font-medium tabular-nums">{{ dashboard.catalogue.out_of_stock }}</p>
          </div>
        </div>
      </section>
    </template>

    <template v-else>
      <!-- No application yet -->
      <section v-if="state === 'none'" class="rc-card p-8 text-center">
        <RcFeatureIcon
          class="mx-auto"
          size="lg"
          icon="M20.5 7.5 12 3 3.5 7.5m17 0L12 12m8.5-4.5v9L12 21m0-9L3.5 7.5m8.5 4.5v9m-8.5-13.5v9L12 21"
        />
        <h2 class="mt-5 text-lg font-medium">RefConcept'te satış yapın</h2>
        <p class="mx-auto mt-3 max-w-[52ch] leading-relaxed text-ink-secondary">
          Ürünleriniz, yapay zekânın ürettiği tasarımlarda müşterilerin karşısına çıkar.
          Başvurunuzu tamamlayın, ekibimiz belgelerinizi inceleyip hesabınızı açsın.
        </p>
        <RcButton to="/onboarding" size="lg" class="mt-7">Başvuruyu başlat</RcButton>
      </section>

      <!-- Draft in progress -->
      <section v-else-if="state === 'draft'" class="rc-card p-6 sm:p-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <p class="text-sm text-muted">Başvurunuz taslak durumda</p>
            <h2 class="mt-1 text-lg font-medium">{{ application?.company_name }}</h2>
          </div>
          <span class="text-2xl font-medium tabular-nums">%{{ completion }}</span>
        </div>

        <div class="mt-4 h-2 w-full overflow-hidden rounded-pill bg-neutral-150">
          <div class="h-full rounded-pill bg-gold" :style="{ width: `${completion}%` }" />
        </div>

        <p class="mt-5 text-sm leading-relaxed text-ink-secondary">
          Kalan adımları tamamladığınızda başvurunuzu incelemeye gönderebilirsiniz.
        </p>

        <RcButton to="/onboarding" class="mt-6">Başvuruya devam et</RcButton>
      </section>

      <!-- Under review -->
      <section v-else-if="state === 'submitted' || state === 'in_review'" class="rc-card p-6 sm:p-8">
        <span class="rounded-pill bg-warning-subtle px-3 py-1 text-xs text-warning-strong">
          {{ application?.status_label }}
        </span>

        <h2 class="mt-4 text-lg font-medium">Başvurunuz inceleniyor</h2>
        <p class="mt-3 max-w-[56ch] leading-relaxed text-ink-secondary">
          {{ application?.company_name }} için başvurunuz ekibimize ulaştı. Belgeleriniz
          incelendikten sonra sonucu e-posta ile bildireceğiz.
        </p>

        <RcButton to="/onboarding" variant="secondary" class="mt-6">Başvurumu görüntüle</RcButton>
      </section>

      <!-- Rejected -->
      <section v-else-if="state === 'rejected'" class="rc-card p-6 sm:p-8">
        <span class="rounded-pill bg-danger-subtle px-3 py-1 text-xs text-danger-strong">
          Onaylanmadı
        </span>

        <h2 class="mt-4 text-lg font-medium">Başvurunuz onaylanmadı</h2>

        <!-- The reason is shown, not hidden: without it the applicant cannot fix anything. -->
        <p v-if="application?.decision_reason" class="mt-3 rounded-sm bg-bg-muted p-4 text-sm leading-relaxed">
          {{ application.decision_reason }}
        </p>

        <p class="mt-4 max-w-[56ch] leading-relaxed text-ink-secondary">
          Eksikleri tamamlayarak yeni bir başvuru oluşturabilirsiniz.
        </p>
      </section>

      <section v-else class="rc-card p-6 sm:p-8">
        <h2 class="text-lg font-medium">{{ application?.status_label }}</h2>
        <RcButton to="/onboarding" variant="secondary" class="mt-5">Başvurumu görüntüle</RcButton>
      </section>
    </template>
  </div>
</template>
