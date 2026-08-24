<script setup lang="ts">
import type { ImportBatchDetail, ImportRowResult } from '@refconcept/ui/types'

/**
 * One import, from column mapping to commit.
 *
 * The screen is built around making the seller confident before anything is written.
 * They see which column the importer thinks is the price, they run a dry run, they
 * read the rows that failed — and only then does the commit button become real. An
 * importer that just says "import" and reports afterwards leaves somebody
 * reconciling four hundred products by hand.
 */
definePageMeta({ middleware: 'auth' })

const route = useRoute()
const api = useApi()
const batchId = route.params.id as string

const batch = ref<ImportBatchDetail | null>(null)
const rows = ref<ImportRowResult[]>([])
const rowFilter = ref<'invalid' | 'valid'>('invalid')
const loadError = ref<string | null>(null)
const working = ref(false)
const actionError = ref<string | null>(null)
const actionMessage = ref<string | null>(null)

/** Header → field, edited locally and saved as one payload. */
const mapping = reactive<Record<string, string>>({})

async function load() {
  try {
    const response = await api.get<{ data: ImportBatchDetail }>(`/api/v1/seller/imports/${batchId}`)

    batch.value = response.data

    for (const header of response.data.detected_headers) {
      mapping[header] = response.data.mapping[header] ?? ''
    }
  } catch (error) {
    loadError.value = error instanceof ApiError
      ? ({ 404: 'Bu içe aktarma bulunamadı.' }[error.status] ?? error.message)
      : 'İçe aktarma yüklenemedi.'
  }
}

async function loadRows() {
  if (!batch.value) return

  try {
    const response = await api.get<{ data: ImportRowResult[] }>(
      `/api/v1/seller/imports/${batchId}/rows`,
      { status: rowFilter.value, per_page: 100 },
    )

    rows.value = response.data
  } catch {
    rows.value = []
  }
}

await load()

useHead(() => ({ title: batch.value?.original_name ?? 'İçe aktarma' }))

watch(rowFilter, loadRows)

/** A field can only be claimed by one column; the others grey it out. */
function isFieldTaken(field: string, header: string): boolean {
  return field !== '' && Object.entries(mapping).some(([key, value]) => value === field && key !== header)
}

async function act(path: string, onSuccess: string) {
  working.value = true
  actionError.value = null
  actionMessage.value = null

  try {
    await api.post(`/api/v1/seller/imports/${batchId}/${path}`)
    await load()
    await loadRows()

    actionMessage.value = batch.value?.status === 'failed'
      ? null
      : onSuccess

    if (batch.value?.status === 'failed') {
      actionError.value = batch.value.failure_reason
    }
  } catch (error) {
    actionError.value = error instanceof ApiError ? error.message : 'İşlem tamamlanamadı.'
  } finally {
    working.value = false
  }
}

async function saveMapping() {
  working.value = true
  actionError.value = null

  try {
    const response = await api.patch<{ data: ImportBatchDetail }>(
      `/api/v1/seller/imports/${batchId}/mapping`,
      { mapping },
    )

    batch.value = response.data
    actionMessage.value = 'Eşleştirme kaydedildi.'
  } catch (error) {
    actionError.value = error instanceof ApiError
      ? (error.fieldError('mapping') ?? error.message)
      : 'Eşleştirme kaydedilemedi.'
  } finally {
    working.value = false
  }
}

const runDryRun = () => act('validate', 'Ön izleme tamamlandı.')
const commit = () => act('commit', 'Ürünler kataloğa aktarıldı.')

const isDone = computed(() => batch.value?.status === 'completed')
</script>

<template>
  <div class="space-y-6">
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <template v-else-if="batch">
      <header>
        <NuxtLink to="/imports" class="text-sm text-ink-secondary hover:text-ink">
          ← Toplu aktarma
        </NuxtLink>
        <div class="mt-3 flex flex-wrap items-center gap-3">
          <h1 class="text-2xl font-medium">{{ batch.original_name }}</h1>
          <RcStatusPill
            :status="batch.status === 'completed' ? 'approved' : batch.status === 'failed' ? 'rejected' : 'in_review'"
            :label="batch.status_label"
          />
        </div>
        <p class="mt-1.5 text-sm text-ink-secondary">{{ batch.total_rows }} satır okundu.</p>
      </header>

      <RcAlert v-if="actionMessage" tone="success">{{ actionMessage }}</RcAlert>
      <RcAlert v-if="actionError" tone="danger">{{ actionError }}</RcAlert>

      <!-- Outcome, once there is one -->
      <section v-if="isDone" class="rc-card p-6 sm:p-8">
        <h2 class="text-lg font-medium">Aktarma tamamlandı</h2>
        <dl class="mt-5 grid gap-4 sm:grid-cols-3">
          <div class="rounded-md bg-bg-muted p-4">
            <dt class="text-xs text-muted">Yeni ürün</dt>
            <dd class="mt-1 text-2xl font-medium tabular-nums">{{ batch.created_rows }}</dd>
          </div>
          <div class="rounded-md bg-bg-muted p-4">
            <dt class="text-xs text-muted">Güncellenen</dt>
            <dd class="mt-1 text-2xl font-medium tabular-nums">{{ batch.updated_rows }}</dd>
          </div>
          <div class="rounded-md bg-bg-muted p-4">
            <dt class="text-xs text-muted">Aktarılmayan</dt>
            <dd class="mt-1 text-2xl font-medium tabular-nums">{{ batch.error_rows }}</dd>
          </div>
        </dl>
        <RcButton to="/products" class="mt-6" variant="secondary">Ürünlerime git</RcButton>
      </section>

      <div v-else class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="space-y-6">
          <!-- Column mapping -->
          <section class="rc-card p-6 sm:p-8">
            <h2 class="text-lg font-medium">Sütun eşleştirme</h2>
            <p class="mt-1.5 max-w-[64ch] text-sm leading-relaxed text-ink-secondary">
              Dosyanızdaki sütunları RefConcept alanlarıyla eşleştirin. Tahminler
              başlık adlarınızdan çıkarıldı; yanlış olanları düzeltebilir, kullanmak
              istemediğiniz sütunları boş bırakabilirsiniz.
            </p>

            <div class="mt-6 space-y-3">
              <div
                v-for="header in batch.detected_headers"
                :key="header"
                class="grid items-center gap-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]"
              >
                <div class="truncate rounded-sm bg-bg-muted px-3 py-2.5 text-sm">
                  {{ header }}
                </div>

                <svg class="rc-icon hidden size-4 text-muted sm:block" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M5 12h14m0 0-5-5m5 5-5 5" />
                </svg>

                <select
                  v-model="mapping[header]"
                  :disabled="!batch.can_validate || working"
                  class="w-full rounded-sm border border-line bg-surface px-3 py-2.5 text-sm disabled:opacity-60"
                >
                  <option value="">Kullanma</option>
                  <option
                    v-for="field in batch.fields"
                    :key="field.field"
                    :value="field.field"
                    :disabled="isFieldTaken(field.field, header)"
                  >
                    {{ field.label }}{{ field.required ? ' *' : '' }}
                  </option>
                </select>
              </div>
            </div>

            <div class="mt-6 flex flex-wrap items-center gap-3">
              <RcButton
                size="sm"
                variant="secondary"
                :disabled="!batch.can_validate || working"
                @click="saveMapping"
              >
                Eşleştirmeyi kaydet
              </RcButton>
            </div>
          </section>

          <!-- Rows -->
          <section v-if="batch.status === 'validated'" class="rc-card p-6 sm:p-8">
            <header class="flex flex-wrap items-center justify-between gap-4">
              <h2 class="text-lg font-medium">Satırlar</h2>

              <div class="flex gap-1.5">
                <button
                  v-for="option in [
                    { value: 'invalid', label: `Hatalı (${batch.error_rows})` },
                    { value: 'valid', label: `Geçerli (${batch.valid_rows})` },
                  ]"
                  :key="option.value"
                  type="button"
                  class="rounded-pill border px-3.5 py-1.5 text-xs transition-colors"
                  :class="rowFilter === option.value
                    ? 'border-charcoal bg-charcoal text-white'
                    : 'border-line text-ink-secondary hover:bg-bg-muted'"
                  @click="rowFilter = option.value as 'invalid' | 'valid'"
                >
                  {{ option.label }}
                </button>
              </div>
            </header>

            <p v-if="rows.length === 0" class="mt-5 rounded-md bg-bg-muted p-5 text-sm text-ink-secondary">
              {{ rowFilter === 'invalid' ? 'Hatalı satır yok.' : 'Geçerli satır yok.' }}
            </p>

            <ul v-else class="mt-5 space-y-3">
              <li
                v-for="row in rows"
                :key="row.line_number"
                class="rounded-md border border-line p-4"
                :class="row.status === 'invalid' ? 'border-danger-subtle bg-danger-subtle/30' : ''"
              >
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <p class="text-sm font-medium">Satır {{ row.line_number }}</p>
                  <RcStatusPill
                    v-if="row.action"
                    :status="row.action === 'create' ? 'approved' : 'in_review'"
                    :label="row.action === 'create' ? 'Yeni ürün' : 'Güncelleme'"
                    size="sm"
                  />
                </div>

                <ul v-if="row.errors.length" class="mt-2.5 space-y-1">
                  <li v-for="error in row.errors" :key="error" class="text-sm text-danger-strong">
                    {{ error }}
                  </li>
                </ul>

                <!-- The seller's own cells, so they can see what they wrote. -->
                <p class="mt-2.5 truncate text-xs text-muted">
                  {{ Object.values(row.raw).filter(Boolean).join(' · ') }}
                </p>
              </li>
            </ul>
          </section>
        </div>

        <!-- Actions -->
        <aside class="space-y-6 lg:sticky lg:top-6 lg:self-start">
          <section class="rc-card p-6">
            <h2 class="text-sm font-medium">Sırasıyla</h2>

            <ol class="mt-4 space-y-4 text-sm leading-relaxed">
              <li class="flex gap-3">
                <span
                  class="grid size-6 shrink-0 place-items-center rounded-pill text-xs"
                  :class="batch.mapping ? 'bg-success-subtle text-success-strong' : 'bg-bg-muted'"
                >1</span>
                <span :class="batch.missing_required.length ? 'text-ink' : 'text-ink-secondary'">
                  Sütunları eşleştirin
                  <span v-if="batch.missing_required.length" class="block text-xs text-danger-strong">
                    Eksik: {{ batch.missing_required.join(', ') }}
                  </span>
                </span>
              </li>
              <li class="flex gap-3">
                <span
                  class="grid size-6 shrink-0 place-items-center rounded-pill text-xs"
                  :class="batch.status === 'validated' ? 'bg-success-subtle text-success-strong' : 'bg-bg-muted'"
                >2</span>
                <span class="text-ink-secondary">Ön izlemeyi çalıştırın — hiçbir şey yazılmaz</span>
              </li>
              <li class="flex gap-3">
                <span class="grid size-6 shrink-0 place-items-center rounded-pill bg-bg-muted text-xs">3</span>
                <span class="text-ink-secondary">Onaylayın ve kataloğa aktarın</span>
              </li>
            </ol>

            <div class="mt-6 flex flex-col gap-2.5">
              <RcButton
                block
                variant="secondary"
                :loading="working"
                :disabled="working || !batch.can_validate || batch.missing_required.length > 0"
                @click="runDryRun"
              >
                Ön izlemeyi çalıştır
              </RcButton>

              <RcButton
                block
                :loading="working"
                :disabled="working || !batch.can_commit || batch.valid_rows === 0"
                @click="commit"
              >
                {{ batch.valid_rows > 0 ? `${batch.valid_rows} satırı aktar` : 'Aktar' }}
              </RcButton>
            </div>

            <p v-if="batch.status === 'validated' && batch.error_rows > 0" class="mt-4 text-xs leading-relaxed text-muted">
              Hatalı {{ batch.error_rows }} satır aktarılmaz, diğerleri etkilenmez.
              Dosyayı düzeltip yeniden yükleyebilirsiniz.
            </p>
          </section>

          <section v-if="batch.status === 'validated'" class="rc-card p-6">
            <h2 class="text-sm font-medium">Ön izleme sonucu</h2>
            <dl class="mt-4 space-y-2.5 text-sm">
              <div class="flex justify-between">
                <dt class="text-muted">Geçerli</dt>
                <dd class="tabular-nums text-success-strong">{{ batch.valid_rows }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-muted">Hatalı</dt>
                <dd class="tabular-nums" :class="batch.error_rows > 0 ? 'text-danger-strong' : ''">
                  {{ batch.error_rows }}
                </dd>
              </div>
            </dl>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>
