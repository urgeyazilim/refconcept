<script setup lang="ts">
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

/**
 * The dashboard's job right now is to answer one question honestly: where is my
 * application, and what do I do next. Product, order and finance panels arrive with
 * the phases that build them rather than as empty placeholders here.
 */
const state = computed(() => {
  if (application.value === null) return 'none'

  return application.value.status
})
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6">
    <header>
      <h1 class="text-2xl font-medium">Hoş geldiniz{{ displayName ? `, ${displayName}` : '' }}</h1>
    </header>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

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

    <!-- Approved -->
    <section v-else-if="state === 'approved'" class="rc-card p-6 sm:p-8">
      <span class="rounded-pill bg-success-subtle px-3 py-1 text-xs text-success-strong">
        Onaylandı
      </span>

      <h2 class="mt-4 text-lg font-medium">Satıcı hesabınız aktif</h2>
      <p class="mt-3 max-w-[56ch] leading-relaxed text-ink-secondary">
        {{ application?.display_name }} artık RefConcept'te satış yapabilir. Ürün
        kataloğu, sipariş ve finans ekranları sırasıyla açılıyor.
      </p>
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
  </div>
</template>
