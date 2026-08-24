<script setup lang="ts">
import type {
  OnboardingMeta,
  SellerDocumentSummary,
  SellerAgreementSummary,
  SellerApplication,
} from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Satıcı başvurusu' })

const api = useApi()

const application = ref<SellerApplication | null>(null)
const meta = ref<OnboardingMeta | null>(null)
const agreements = ref<SellerAgreementSummary[]>([])
const loading = ref(true)
const banner = ref<{ tone: 'success' | 'danger' | 'info', text: string } | null>(null)

/** Per-section state so one failing save cannot blank another section's errors. */
const errors = ref<Record<string, Record<string, string[]>>>({})
const saving = ref<string | null>(null)

const company = reactive({
  company_name: '',
  display_name: '',
  legal_form: 'limited_sirket',
  contact_email: '',
  contact_phone: '',
  website: '',
})

const legal = reactive({
  legal_name: '',
  tax_office: '',
  tax_number: '',
  national_id: '',
  mersis_number: '',
})

const tax = reactive({
  taxpayer_type: 'corporate',
  default_vat_rate_bps: 2000,
})

const contact = reactive({
  type: 'primary',
  full_name: '',
  email: '',
  phone: '',
  title: '',
})

const address = reactive({
  type: 'registered',
  city: '',
  district: '',
  address_line1: '',
  postal_code: '',
})

const bank = reactive({
  account_holder: '',
  bank_name: '',
  iban: '',
})

const legalForms = [
  { value: 'anonim_sirket', label: 'Anonim Şirket' },
  { value: 'limited_sirket', label: 'Limited Şirket' },
  { value: 'sahis_sirketi', label: 'Şahıs Şirketi' },
  { value: 'kollektif_sirket', label: 'Kollektif Şirket' },
  { value: 'diger', label: 'Diğer' },
]

const taxpayerTypes = [
  { value: 'corporate', label: 'Şirket' },
  { value: 'sole_proprietor', label: 'Şahıs şirketi' },
  { value: 'individual', label: 'Bireysel' },
]

const documentTypes = [
  { value: 'tax_certificate', label: 'Vergi levhası' },
  { value: 'trade_registry_gazette', label: 'Ticaret sicil gazetesi' },
  { value: 'signature_circular', label: 'İmza sirküleri' },
  { value: 'identity_document', label: 'Kimlik belgesi' },
  { value: 'bank_account_proof', label: 'Banka hesap belgesi' },
  { value: 'activity_certificate', label: 'Faaliyet belgesi' },
]

/** An individual seller is identified by TCKN, a company by VKN. */
const isIndividual = computed(() => tax.taxpayer_type === 'individual')

const isEditable = computed(() => application.value?.is_editable === true)

/** The account payouts go to; only its masked form is ever available here. */
const primaryBankAccount = computed(() => application.value?.bank_accounts?.[0] ?? null)
const status = computed(() => application.value?.status ?? null)

async function load() {
  loading.value = true

  try {
    const [applicationResponse, agreementsResponse] = await Promise.all([
      api.get<{ data: SellerApplication | null, meta?: OnboardingMeta }>('/api/v1/seller/application'),
      api.get<{ data: SellerAgreementSummary[] }>('/api/v1/seller/agreements'),
    ])

    application.value = applicationResponse.data
    meta.value = applicationResponse.meta ?? null
    agreements.value = agreementsResponse.data

    if (application.value) {
      hydrate(application.value)
    }
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'Başvuru yüklenemedi.',
    }
  } finally {
    loading.value = false
  }
}

function hydrate(data: SellerApplication) {
  Object.assign(company, {
    company_name: data.company_name ?? '',
    display_name: data.display_name ?? '',
    legal_form: data.legal_form ?? 'limited_sirket',
    contact_email: data.contact_email ?? '',
    contact_phone: data.contact_phone ?? '',
    website: data.website ?? '',
  })

  if (data.legal_entity) Object.assign(legal, data.legal_entity)
  if (data.tax_profile) Object.assign(tax, data.tax_profile)

  const primaryContact = (data.contacts ?? []).find((c) => c.type === 'primary')
  if (primaryContact) Object.assign(contact, primaryContact)

  const registered = (data.addresses ?? []).find((a) => a.type === 'registered')
  if (registered) Object.assign(address, registered)

  const primaryBank = (data.bank_accounts ?? [])[0]
  if (primaryBank) {
    bank.account_holder = primaryBank.account_holder ?? ''
    bank.bank_name = primaryBank.bank_name ?? ''
    // Never prefill the IBAN: only its masked form ever leaves the server.
    bank.iban = ''
  }
}

async function createApplication() {
  saving.value = 'company'
  errors.value.company = {}

  try {
    const response = await api.post<{ data: SellerApplication, meta: OnboardingMeta }>('/api/v1/seller/application', {
      ...company,
      website: company.website || null,
    })

    application.value = response.data
    meta.value = response.meta
    banner.value = { tone: 'success', text: 'Başvurunuz oluşturuldu. Adımları tamamlayın.' }
  } catch (error) {
    handleError('company', error)
  } finally {
    saving.value = null
  }
}

async function saveSection(section: string, payload: Record<string, unknown>, key: string) {
  saving.value = key
  errors.value[key] = {}

  try {
    const response = await api.put<{ data: SellerApplication, meta: OnboardingMeta }>(
      `/api/v1/seller/application/sections/${section}`,
      payload,
    )

    application.value = response.data
    meta.value = response.meta
    banner.value = { tone: 'success', text: 'Kaydedildi.' }
  } catch (error) {
    handleError(key, error)
  } finally {
    saving.value = null
  }
}

function handleError(key: string, error: unknown) {
  if (error instanceof ApiError && error.isValidation) {
    errors.value[key] = error.errors
    banner.value = { tone: 'danger', text: 'Lütfen işaretli alanları düzeltin.' }
  } else {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'Kaydedilemedi.',
    }
  }
}

async function acceptAgreement(agreement: SellerAgreementSummary) {
  try {
    await api.post(`/api/v1/seller/agreements/${agreement.id}/accept`)
    await load()
    banner.value = { tone: 'success', text: `${agreement.title} onaylandı.` }
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'Sözleşme onaylanamadı.',
    }
  }
}

const uploading = ref<string | null>(null)

async function uploadDocument(event: Event, type: string) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  if (!file) return

  uploading.value = type

  try {
    const body = new FormData()
    body.append('type', type)
    body.append('file', file)

    await api.request('/api/v1/seller/documents', { method: 'POST', body })
    await load()
    banner.value = { tone: 'success', text: 'Belge yüklendi.' }
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'Belge yüklenemedi.',
    }
  } finally {
    uploading.value = null
    input.value = ''
  }
}

async function submitApplication() {
  saving.value = 'submit'

  try {
    const response = await api.post<{ data: SellerApplication, meta: OnboardingMeta }>('/api/v1/seller/application/submit')
    application.value = response.data
    meta.value = response.meta
    banner.value = { tone: 'success', text: 'Başvurunuz incelemeye gönderildi.' }
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError
        ? (error.fieldError('status') ?? error.message)
        : 'Başvuru gönderilemedi.',
    }
  } finally {
    saving.value = null
  }
}

function documentFor(type: string): SellerDocumentSummary | undefined {
  return (application.value?.documents ?? []).find((d) => d.type === type)
}

await load()
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6">
    <header>
      <h1 class="text-2xl font-medium">Satıcı başvurusu</h1>
      <p class="mt-2 text-sm text-ink-secondary">
        RefConcept'te satış yapmak için firma bilgilerinizi, belgelerinizi ve
        sözleşme onaylarınızı tamamlayın.
      </p>
    </header>

    <RcAlert v-if="banner" :tone="banner.tone">{{ banner.text }}</RcAlert>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <!-- No application yet: the first section creates it. -->
    <section v-else-if="!application" class="rc-card p-6 sm:p-8">
      <h2 class="mb-6 text-lg font-medium">Firma bilgileri</h2>

      <form class="space-y-5" novalidate @submit.prevent="createApplication">
        <div class="grid gap-5 sm:grid-cols-2">
          <RcField
            v-model="company.company_name"
            label="Firma unvanı"
            name="company_name"
            required
            :errors="errors.company?.company_name"
          />
          <RcField
            v-model="company.display_name"
            label="Mağaza adı"
            name="display_name"
            required
            hint="Müşterilerin göreceği ad."
            :errors="errors.company?.display_name"
          />
        </div>

        <div>
          <label for="legal_form" class="mb-1.5 block text-sm font-medium">Şirket türü</label>
          <select
            id="legal_form"
            v-model="company.legal_form"
            class="w-full rounded-sm border border-line bg-surface px-4 py-3 text-sm"
          >
            <option v-for="form in legalForms" :key="form.value" :value="form.value">
              {{ form.label }}
            </option>
          </select>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
          <RcField
            v-model="company.contact_email"
            label="İletişim e-postası"
            name="contact_email"
            type="email"
            required
            :errors="errors.company?.contact_email"
          />
          <RcField
            v-model="company.contact_phone"
            label="İletişim telefonu"
            name="contact_phone"
            required
            :errors="errors.company?.contact_phone"
          />
        </div>

        <RcField
          v-model="company.website"
          label="Web sitesi"
          name="website"
          :errors="errors.company?.website"
        />

        <RcButton type="submit" :loading="saving === 'company'">Başvuruyu başlat</RcButton>
      </form>
    </section>

    <template v-else>
      <!-- Progress and status -->
      <section class="rc-card p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <p class="text-sm text-muted">Başvuru durumu</p>
            <p class="mt-1 text-lg font-medium">{{ application.status_label }}</p>
          </div>
          <span class="text-2xl font-medium tabular-nums">%{{ meta?.completion_percent ?? 0 }}</span>
        </div>

        <div class="mt-4 h-2 w-full overflow-hidden rounded-pill bg-neutral-150">
          <div
            class="h-full rounded-pill bg-gold transition-[width] duration-[--rc-duration-slow]"
            :style="{ width: `${meta?.completion_percent ?? 0}%` }"
          />
        </div>

        <ul class="mt-6 grid gap-2 sm:grid-cols-2">
          <li
            v-for="step in meta?.checklist ?? []"
            :key="step.step"
            class="flex items-start gap-2.5 text-sm"
          >
            <span
              class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-pill text-[11px]"
              :class="step.completed ? 'bg-success-subtle text-success-strong' : 'bg-neutral-150 text-muted'"
            >
              {{ step.completed ? '✓' : '·' }}
            </span>
            <span>
              <span :class="step.completed ? '' : 'text-ink-secondary'">{{ step.label }}</span>
              <span v-if="!step.completed && step.detail" class="block text-xs text-muted">
                {{ step.detail }}
              </span>
            </span>
          </li>
        </ul>
      </section>

      <RcAlert v-if="application.decision_reason" :tone="status === 'approved' ? 'success' : 'warning'">
        <strong>Karar gerekçesi:</strong> {{ application.decision_reason }}
      </RcAlert>

      <RcAlert v-if="!isEditable" tone="info">
        Başvurunuz {{ application.status_label.toLowerCase() }} durumunda olduğu için
        düzenlenemez.
      </RcAlert>

      <!-- Legal entity -->
      <section class="rc-card p-6 sm:p-8">
        <h2 class="mb-6 text-lg font-medium">Yasal bilgiler</h2>

        <form
          class="space-y-5"
          novalidate
          @submit.prevent="saveSection('legal-entity', {
            legal_name: legal.legal_name,
            tax_office: legal.tax_office || null,
            tax_number: isIndividual ? null : (legal.tax_number || null),
            national_id: isIndividual ? (legal.national_id || null) : null,
            mersis_number: legal.mersis_number || null,
          }, 'legal')"
        >
          <RcField
            v-model="legal.legal_name"
            label="Yasal unvan"
            name="legal_name"
            required
            :disabled="!isEditable"
            :errors="errors.legal?.legal_name"
          />

          <div class="grid gap-5 sm:grid-cols-2">
            <RcField
              v-if="!isIndividual"
              v-model="legal.tax_number"
              label="Vergi numarası (VKN)"
              name="tax_number"
              hint="10 haneli"
              :disabled="!isEditable"
              :errors="errors.legal?.tax_number"
            />
            <RcField
              v-else
              v-model="legal.national_id"
              label="T.C. Kimlik No"
              name="national_id"
              hint="11 haneli"
              :disabled="!isEditable"
              :errors="errors.legal?.national_id"
            />

            <RcField
              v-model="legal.tax_office"
              label="Vergi dairesi"
              name="tax_office"
              :disabled="!isEditable"
              :errors="errors.legal?.tax_office"
            />
          </div>

          <RcField
            v-model="legal.mersis_number"
            label="MERSİS numarası"
            name="mersis_number"
            hint="16 haneli, varsa"
            :disabled="!isEditable"
            :errors="errors.legal?.mersis_number"
          />

          <RcButton v-if="isEditable" type="submit" :loading="saving === 'legal'">Kaydet</RcButton>
        </form>
      </section>

      <!-- Tax profile -->
      <section class="rc-card p-6 sm:p-8">
        <h2 class="mb-6 text-lg font-medium">Vergi profili</h2>

        <form
          class="space-y-5"
          novalidate
          @submit.prevent="saveSection('tax-profile', {
            taxpayer_type: tax.taxpayer_type,
            default_vat_rate_bps: Number(tax.default_vat_rate_bps),
          }, 'tax')"
        >
          <div>
            <label for="taxpayer_type" class="mb-1.5 block text-sm font-medium">Mükellef türü</label>
            <select
              id="taxpayer_type"
              v-model="tax.taxpayer_type"
              :disabled="!isEditable"
              class="w-full rounded-sm border border-line bg-surface px-4 py-3 text-sm disabled:bg-bg-muted"
            >
              <option v-for="type in taxpayerTypes" :key="type.value" :value="type.value">
                {{ type.label }}
              </option>
            </select>
            <p class="mt-1.5 text-xs text-muted">
              Zorunlu belgeler mükellef türüne göre değişir.
            </p>
          </div>

          <RcField
            v-model="tax.default_vat_rate_bps"
            label="Varsayılan KDV oranı (baz puan)"
            name="default_vat_rate_bps"
            type="number"
            hint="2000 = %20"
            :disabled="!isEditable"
            :errors="errors.tax?.default_vat_rate_bps"
          />

          <RcButton v-if="isEditable" type="submit" :loading="saving === 'tax'">Kaydet</RcButton>
        </form>
      </section>

      <!-- Primary contact -->
      <section class="rc-card p-6 sm:p-8">
        <h2 class="mb-6 text-lg font-medium">Birincil iletişim kişisi</h2>

        <form
          class="space-y-5"
          novalidate
          @submit.prevent="saveSection('contact', {
            type: 'primary',
            full_name: contact.full_name,
            email: contact.email,
            phone: contact.phone || null,
            title: contact.title || null,
          }, 'contact')"
        >
          <div class="grid gap-5 sm:grid-cols-2">
            <RcField
              v-model="contact.full_name"
              label="Ad soyad"
              name="full_name"
              required
              :disabled="!isEditable"
              :errors="errors.contact?.full_name"
            />
            <RcField
              v-model="contact.title"
              label="Görev"
              name="title"
              :disabled="!isEditable"
              :errors="errors.contact?.title"
            />
          </div>

          <div class="grid gap-5 sm:grid-cols-2">
            <RcField
              v-model="contact.email"
              label="E-posta"
              name="contact_person_email"
              type="email"
              required
              :disabled="!isEditable"
              :errors="errors.contact?.email"
            />
            <RcField
              v-model="contact.phone"
              label="Telefon"
              name="contact_person_phone"
              :disabled="!isEditable"
              :errors="errors.contact?.phone"
            />
          </div>

          <RcButton v-if="isEditable" type="submit" :loading="saving === 'contact'">Kaydet</RcButton>
        </form>
      </section>

      <!-- Registered address -->
      <section class="rc-card p-6 sm:p-8">
        <h2 class="mb-6 text-lg font-medium">Kayıtlı adres</h2>

        <form
          class="space-y-5"
          novalidate
          @submit.prevent="saveSection('address', {
            type: 'registered',
            city: address.city,
            district: address.district || null,
            address_line1: address.address_line1,
            postal_code: address.postal_code || null,
          }, 'address')"
        >
          <div class="grid gap-5 sm:grid-cols-2">
            <RcField
              v-model="address.city"
              label="İl"
              name="city"
              required
              :disabled="!isEditable"
              :errors="errors.address?.city"
            />
            <RcField
              v-model="address.district"
              label="İlçe"
              name="district"
              :disabled="!isEditable"
              :errors="errors.address?.district"
            />
          </div>

          <RcField
            v-model="address.address_line1"
            label="Adres"
            name="address_line1"
            required
            :disabled="!isEditable"
            :errors="errors.address?.address_line1"
          />

          <RcField
            v-model="address.postal_code"
            label="Posta kodu"
            name="postal_code"
            :disabled="!isEditable"
            :errors="errors.address?.postal_code"
          />

          <RcButton v-if="isEditable" type="submit" :loading="saving === 'address'">Kaydet</RcButton>
        </form>
      </section>

      <!-- Bank account -->
      <section class="rc-card p-6 sm:p-8">
        <h2 class="mb-2 text-lg font-medium">Hakediş hesabı</h2>
        <p class="mb-6 text-sm text-ink-secondary">
          Satış hakedişleriniz bu hesaba aktarılır. IBAN şifrelenerek saklanır ve
          ekranda yalnızca son dört hanesi gösterilir.
        </p>

        <div
          v-if="primaryBankAccount"
          class="mb-5 rounded-sm bg-bg-muted px-4 py-3 text-sm"
        >
          Kayıtlı IBAN: <strong class="tabular-nums">{{ primaryBankAccount.iban_masked }}</strong>
        </div>

        <form
          class="space-y-5"
          novalidate
          @submit.prevent="saveSection('bank-account', {
            account_holder: bank.account_holder,
            bank_name: bank.bank_name || null,
            iban: bank.iban,
          }, 'bank')"
        >
          <RcField
            v-model="bank.account_holder"
            label="Hesap sahibi"
            name="account_holder"
            required
            :disabled="!isEditable"
            :errors="errors.bank?.account_holder"
          />

          <div class="grid gap-5 sm:grid-cols-2">
            <RcField
              v-model="bank.bank_name"
              label="Banka"
              name="bank_name"
              :disabled="!isEditable"
              :errors="errors.bank?.bank_name"
            />
            <RcField
              v-model="bank.iban"
              label="IBAN"
              name="iban"
              placeholder="TR__ ____ ____ ____ ____ ____ __"
              :disabled="!isEditable"
              :errors="errors.bank?.iban"
            />
          </div>

          <RcButton v-if="isEditable" type="submit" :loading="saving === 'bank'">Kaydet</RcButton>
        </form>
      </section>

      <!-- Documents -->
      <section class="rc-card p-6 sm:p-8">
        <h2 class="mb-2 text-lg font-medium">Belgeler</h2>
        <p class="mb-6 text-sm text-ink-secondary">
          PDF, JPG veya PNG · en fazla 10 MB. Belgeleriniz özel depolamada tutulur.
        </p>

        <ul class="space-y-3">
          <li
            v-for="type in documentTypes"
            :key="type.value"
            class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-3 last:border-0"
          >
            <div v-for="document in [documentFor(type.value)]" :key="document?.id ?? type.value">
              <p class="text-sm font-medium">{{ type.label }}</p>
              <p v-if="document" class="text-xs text-muted">
                {{ document.original_name }} ·
                <span
                  :class="{
                    'text-success-strong': document.status === 'approved',
                    'text-danger-strong': document.status === 'rejected',
                  }"
                >{{ document.status_label }}</span>
              </p>
              <p v-if="document?.review_note" class="text-xs text-danger-strong">
                {{ document.review_note }}
              </p>
            </div>

            <label v-if="isEditable" class="shrink-0">
              <span
                class="inline-flex cursor-pointer items-center rounded-sm border border-line-strong px-4 py-2 text-sm hover:bg-bg-muted"
              >
                {{ uploading === type.value ? 'Yükleniyor…' : (documentFor(type.value) ? 'Değiştir' : 'Yükle') }}
              </span>
              <input
                :id="`document-${type.value}`"
                type="file"
                class="sr-only"
                accept="application/pdf,image/jpeg,image/png,image/webp"
                @change="uploadDocument($event, type.value)"
              >
            </label>
          </li>
        </ul>
      </section>

      <!-- Agreements -->
      <section class="rc-card p-6 sm:p-8">
        <h2 class="mb-6 text-lg font-medium">Sözleşmeler</h2>

        <ul class="space-y-4">
          <li v-for="agreement in agreements" :key="agreement.id" class="border-b border-line pb-4 last:border-0">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="font-medium">{{ agreement.title }}</p>
                <p class="text-xs text-muted">Sürüm {{ agreement.version }}</p>
              </div>

              <span
                v-if="agreement.accepted"
                class="rounded-pill bg-success-subtle px-3 py-1 text-xs text-success-strong"
              >
                Onaylandı
              </span>
              <RcButton
                v-else-if="isEditable"
                size="sm"
                variant="secondary"
                @click="acceptAgreement(agreement)"
              >
                Okudum, onaylıyorum
              </RcButton>
            </div>

            <details class="mt-3">
              <summary class="cursor-pointer text-sm text-ink-secondary">Metni oku</summary>
              <pre class="mt-3 max-h-64 overflow-y-auto rounded-sm bg-bg-muted p-4 text-xs leading-relaxed whitespace-pre-wrap">{{ agreement.body }}</pre>
            </details>
          </li>
        </ul>
      </section>

      <!-- Submit -->
      <section v-if="isEditable" class="rc-card p-6 sm:p-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <p class="font-medium">Başvuruyu gönder</p>
            <p class="mt-1 text-sm text-ink-secondary">
              {{ meta?.can_submit
                ? 'Tüm adımlar tamamlandı. Başvurunuzu incelemeye gönderebilirsiniz.'
                : 'Eksik adımlar tamamlandığında gönderebilirsiniz.' }}
            </p>
          </div>

          <RcButton
            size="lg"
            :disabled="!meta?.can_submit"
            :loading="saving === 'submit'"
            @click="submitApplication"
          >
            İncelemeye gönder
          </RcButton>
        </div>
      </section>
    </template>
  </div>
</template>
