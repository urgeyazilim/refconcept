<script setup lang="ts">
import type {
  OnboardingMeta,
  SellerApplication,
  SellerDocumentSummary,
} from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })

const api = useApi()
const route = useRoute()

const applicationId = computed(() => String(route.params.id))

const application = ref<SellerApplication | null>(null)
const meta = ref<OnboardingMeta | null>(null)
const loading = ref(true)
const banner = ref<{ tone: 'success' | 'danger' | 'info', text: string } | null>(null)

const decision = reactive({
  reason: '',
  commission_bps: '' as string | number,
})

const acting = ref<string | null>(null)

useHead({ title: () => application.value?.company_name ?? 'Başvuru' })

async function load() {
  loading.value = true

  try {
    const response = await api.get<{ data: SellerApplication, meta: OnboardingMeta }>(
      `/api/v1/admin/seller-applications/${applicationId.value}`,
    )

    application.value = response.data
    meta.value = response.meta
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError
        ? (error.status === 403 ? 'Bu başvuruya erişim yetkiniz yok.' : error.message)
        : 'Başvuru yüklenemedi.',
    }
  } finally {
    loading.value = false
  }
}

await load()

async function startReview() {
  acting.value = 'review'

  try {
    await api.post(`/api/v1/admin/seller-applications/${applicationId.value}/review`)
    await load()
    banner.value = { tone: 'info', text: 'Başvuru incelemenize alındı.' }
  } catch (error) {
    banner.value = { tone: 'danger', text: errorText(error) }
  } finally {
    acting.value = null
  }
}

async function approve() {
  acting.value = 'approve'

  try {
    const response = await api.post<{ data: { seller_code: string } }>(
      `/api/v1/admin/seller-applications/${applicationId.value}/approve`,
      {
        reason: decision.reason,
        commission_bps: decision.commission_bps === '' ? null : Number(decision.commission_bps),
      },
    )

    await load()
    banner.value = {
      tone: 'success',
      text: `Başvuru onaylandı. Satıcı kodu: ${response.data.seller_code}`,
    }
  } catch (error) {
    banner.value = { tone: 'danger', text: errorText(error) }
  } finally {
    acting.value = null
  }
}

async function reject() {
  acting.value = 'reject'

  try {
    await api.post(`/api/v1/admin/seller-applications/${applicationId.value}/reject`, {
      reason: decision.reason,
    })

    await load()
    banner.value = { tone: 'info', text: 'Başvuru reddedildi ve başvurana bildirildi.' }
  } catch (error) {
    banner.value = { tone: 'danger', text: errorText(error) }
  } finally {
    acting.value = null
  }
}

async function reviewDocument(document: SellerDocumentSummary, status: 'approved' | 'rejected') {
  let note: string | null = null

  if (status === 'rejected') {
    note = window.prompt('Red gerekçesi (başvurana gösterilir):')

    // A rejection the applicant cannot act on just becomes a support ticket, so an
    // empty or cancelled prompt aborts rather than rejecting silently.
    if (note === null || note.trim() === '') return
  }

  try {
    await api.post(`/api/v1/admin/seller-documents/${document.id}/review`, { status, note })
    await load()
  } catch (error) {
    banner.value = { tone: 'danger', text: errorText(error) }
  }
}

async function openDocument(document: SellerDocumentSummary) {
  try {
    const response = await api.get<{ data: { url: string } }>(
      `/api/v1/seller/documents/${document.id}/link`,
    )

    window.open(response.data.url, '_blank', 'noopener')
  } catch (error) {
    banner.value = { tone: 'danger', text: errorText(error) }
  }
}

function errorText(error: unknown): string {
  if (error instanceof ApiError) {
    return error.fieldError('reason')
      ?? error.fieldError('status')
      ?? error.message
  }

  return 'İşlem tamamlanamadı.'
}

const isDecidable = computed(
  () => application.value !== null && ['submitted', 'in_review'].includes(application.value.status),
)

/** Approving demands both a reason and a complete file. */
const canApprove = computed(
  () => isDecidable.value && decision.reason.trim().length >= 10 && meta.value?.completion_percent === 100,
)

const canReject = computed(() => isDecidable.value && decision.reason.trim().length >= 10)
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-6">
    <NuxtLink to="/" class="inline-flex items-center gap-2 text-sm text-muted hover:text-ink">
      ← İnceleme kuyruğu
    </NuxtLink>

    <RcAlert v-if="banner" :tone="banner.tone">{{ banner.text }}</RcAlert>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <template v-else-if="application">
      <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 class="text-2xl font-medium">{{ application.company_name }}</h1>
          <p class="mt-1.5 text-sm text-muted">
            {{ application.display_name }} · {{ application.contact_email }}
          </p>
        </div>

        <div class="text-right">
          <span
            class="rounded-pill px-3 py-1 text-xs"
            :class="{
              'bg-warning-subtle text-warning-strong': ['submitted', 'in_review'].includes(application.status),
              'bg-success-subtle text-success-strong': application.status === 'approved',
              'bg-danger-subtle text-danger-strong': application.status === 'rejected',
              'bg-bg-muted text-ink-secondary': ['draft', 'withdrawn'].includes(application.status),
            }"
          >
            {{ application.status_label }}
          </span>
          <p class="mt-2 text-xs text-muted">Tamamlanma %{{ meta?.completion_percent ?? 0 }}</p>
        </div>
      </header>

      <RcAlert v-if="application.decision_reason" :tone="application.status === 'approved' ? 'success' : 'warning'">
        <strong>Karar gerekçesi:</strong> {{ application.decision_reason }}
      </RcAlert>

      <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
        <div class="space-y-6">
          <!-- Legal -->
          <section class="rc-card p-6">
            <h2 class="mb-4 text-lg font-medium">Yasal bilgiler</h2>
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              <div>
                <dt class="text-muted">Yasal unvan</dt>
                <dd>{{ application.legal_entity?.legal_name ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-muted">Vergi dairesi</dt>
                <dd>{{ application.legal_entity?.tax_office ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-muted">VKN</dt>
                <dd class="tabular-nums">{{ application.legal_entity?.tax_number ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-muted">TCKN</dt>
                <dd class="tabular-nums">{{ application.legal_entity?.national_id ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-muted">Mükellef türü</dt>
                <dd>{{ application.tax_profile?.taxpayer_type_label ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-muted">KDV oranı</dt>
                <dd>
                  {{ application.tax_profile
                    ? `%${application.tax_profile.default_vat_rate_bps / 100}`
                    : '—' }}
                </dd>
              </div>
            </dl>
          </section>

          <!-- Contact and address -->
          <section class="rc-card p-6">
            <h2 class="mb-4 text-lg font-medium">İletişim ve adres</h2>

            <div v-for="contact in application.contacts ?? []" :key="contact.id" class="mb-4 text-sm last:mb-0">
              <p class="text-muted">{{ contact.type }}</p>
              <p>{{ contact.full_name }} · {{ contact.email }}</p>
            </div>

            <div
              v-for="address in application.addresses ?? []"
              :key="address.id"
              class="border-t border-line pt-4 text-sm"
            >
              <p class="text-muted">{{ address.type }}</p>
              <p>
                {{ address.address_line1 }}<br>
                {{ address.district }} / {{ address.city }}
              </p>
            </div>
          </section>

          <!-- Bank -->
          <section class="rc-card p-6">
            <h2 class="mb-4 text-lg font-medium">Hakediş hesabı</h2>

            <div
              v-for="account in application.bank_accounts ?? []"
              :key="account.id"
              class="text-sm"
            >
              <p>{{ account.account_holder }}</p>
              <p class="text-muted">
                {{ account.bank_name ?? 'Banka belirtilmedi' }} ·
                <span class="tabular-nums">{{ account.iban_masked }}</span>
              </p>
            </div>

            <p v-if="!application.bank_accounts?.length" class="text-sm text-muted">
              Banka hesabı girilmemiş.
            </p>

            <!-- The reviewer sees the masked value only; nobody needs the full IBAN
                 on screen to decide an application. -->
            <p class="mt-3 text-xs text-muted">
              IBAN şifreli saklanır; ekranda yalnızca son dört hane gösterilir.
            </p>
          </section>

          <!-- Documents -->
          <section class="rc-card p-6">
            <h2 class="mb-4 text-lg font-medium">Belgeler</h2>

            <ul class="space-y-3">
              <li
                v-for="document in application.documents ?? []"
                :key="document.id"
                class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-3 last:border-0"
              >
                <div class="min-w-0">
                  <p class="text-sm font-medium">{{ document.type_label }}</p>
                  <p class="truncate text-xs text-muted">
                    {{ document.original_name }} ·
                    {{ Math.round(document.size_bytes / 1024) }} KB ·
                    <span
                      :class="{
                        'text-success-strong': document.status === 'approved',
                        'text-danger-strong': document.status === 'rejected',
                      }"
                    >{{ document.status_label }}</span>
                  </p>
                </div>

                <div class="flex shrink-0 gap-2">
                  <RcButton size="sm" variant="ghost" @click="openDocument(document)">Aç</RcButton>
                  <template v-if="isDecidable">
                    <RcButton size="sm" variant="ghost" @click="reviewDocument(document, 'approved')">
                      Onayla
                    </RcButton>
                    <RcButton size="sm" variant="ghost" @click="reviewDocument(document, 'rejected')">
                      Reddet
                    </RcButton>
                  </template>
                </div>
              </li>
            </ul>

            <p v-if="!application.documents?.length" class="text-sm text-muted">
              Belge yüklenmemiş.
            </p>
          </section>
        </div>

        <!-- Decision panel -->
        <aside class="space-y-6">
          <section class="rc-card p-6">
            <h2 class="mb-4 text-lg font-medium">Kontrol listesi</h2>

            <ul class="space-y-2 text-sm">
              <li v-for="step in meta?.checklist ?? []" :key="step.step" class="flex items-start gap-2.5">
                <span
                  class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-pill text-[11px]"
                  :class="step.completed ? 'bg-success-subtle text-success-strong' : 'bg-neutral-150 text-muted'"
                >
                  {{ step.completed ? '✓' : '·' }}
                </span>
                <span>
                  {{ step.label }}
                  <span v-if="!step.completed && step.detail" class="block text-xs text-muted">
                    {{ step.detail }}
                  </span>
                </span>
              </li>
            </ul>
          </section>

          <section v-if="isDecidable" class="rc-card p-6">
            <h2 class="mb-4 text-lg font-medium">Karar</h2>

            <RcButton
              v-if="application.status === 'submitted'"
              variant="secondary"
              block
              class="mb-4"
              :loading="acting === 'review'"
              @click="startReview"
            >
              İncelemeye al
            </RcButton>

            <label for="reason" class="mb-1.5 block text-sm font-medium">
              Gerekçe <span class="text-danger">*</span>
            </label>
            <textarea
              id="reason"
              v-model="decision.reason"
              rows="4"
              class="w-full rounded-sm border border-line bg-surface px-4 py-3 text-sm"
              placeholder="Kararınızın gerekçesini yazın. Reddederseniz başvurana gösterilir."
            />
            <p class="mt-1.5 text-xs text-muted">En az 10 karakter. Denetim kaydına işlenir.</p>

            <div class="mt-5">
              <RcField
                v-model="decision.commission_bps"
                label="Komisyon (baz puan)"
                name="commission_bps"
                type="number"
                hint="Boş bırakılırsa platform varsayılanı uygulanır. 1250 = %12,5"
              />
            </div>

            <div class="mt-6 space-y-3">
              <RcButton block :disabled="!canApprove" :loading="acting === 'approve'" @click="approve">
                Onayla
              </RcButton>
              <RcButton
                block
                variant="danger"
                :disabled="!canReject"
                :loading="acting === 'reject'"
                @click="reject"
              >
                Reddet
              </RcButton>
            </div>

            <p v-if="meta && meta.completion_percent < 100" class="mt-4 text-xs text-warning-strong">
              Eksik adımlar varken başvuru onaylanamaz.
            </p>
          </section>

          <section v-else class="rc-card p-6">
            <p class="text-sm text-ink-secondary">
              Bu başvuru {{ application.status_label.toLowerCase() }} durumunda; karar verilemez.
            </p>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>
