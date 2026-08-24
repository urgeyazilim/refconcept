<script setup lang="ts">
import type { ImportBatchSummary } from '@refconcept/ui/types'

/**
 * Bulk import.
 *
 * A seller with four hundred products will not type them in, so this is how the
 * catalogue actually gets populated. The list exists as much for the *old* imports as
 * for new ones: three months later, "why did the price on line 251 come out wrong"
 * has an answer, and it is here.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Toplu ürün aktarma' })

const api = useApi()
const config = useRuntimeConfig()

const batches = ref<ImportBatchSummary[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const uploading = ref(false)
const uploadError = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const response = await api.get<{ data: ImportBatchSummary[] }>('/api/v1/seller/imports')
    batches.value = response.data
  } catch (error) {
    loadError.value = error instanceof ApiError
      ? (error.status === 403 ? 'Toplu aktarma için satıcı hesabınızın onaylanması gerekiyor.' : error.message)
      : 'İçe aktarmalar yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

async function onFileSelected(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  if (!file) return

  uploading.value = true
  uploadError.value = null

  try {
    const body = new FormData()
    body.append('file', file)

    const response = await api.request<{ data: ImportBatchSummary }>('/api/v1/seller/imports', {
      method: 'POST',
      body,
    })

    // Straight to the mapping screen: the upload on its own accomplishes nothing, and
    // the next decision is the seller's.
    await navigateTo(`/imports/${response.data.id}`)
  } catch (error) {
    uploadError.value = error instanceof ApiError
      ? (error.fieldError('file') ?? error.message)
      : 'Dosya yüklenemedi.'
  } finally {
    uploading.value = false
    input.value = ''
  }
}

/**
 * The template is served by the API rather than bundled with the app, so it can never
 * fall out of step with the columns the importer actually understands.
 */
const templateUrl = `${config.public.apiBase}/api/v1/seller/imports/template`

const { token } = useAuth()

async function downloadTemplate() {
  const response = await fetch(templateUrl, {
    headers: { Authorization: `Bearer ${token.value}`, Accept: 'text/csv' },
  })

  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = url
  link.download = 'refconcept-urun-sablonu.csv'
  link.click()

  URL.revokeObjectURL(url)
}

function outcomeOf(batch: ImportBatchSummary): string {
  if (batch.status === 'completed') {
    return `${batch.created_rows} yeni · ${batch.updated_rows} güncellendi`
  }

  if (batch.status === 'validated') {
    return `${batch.valid_rows} geçerli · ${batch.error_rows} hatalı`
  }

  if (batch.status === 'failed') {
    return batch.failure_reason ?? 'Başarısız'
  }

  return `${batch.total_rows} satır`
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-medium">Toplu ürün aktarma</h1>
        <p class="mt-1.5 max-w-[70ch] text-sm leading-relaxed text-ink-secondary">
          CSV veya Excel dosyanızı yükleyin, sütunları eşleştirin, ön izlemeyi çalıştırın.
          Aktarma yalnızca siz onayladıktan sonra yapılır — hiçbir şey siz görmeden
          kataloğa yazılmaz.
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <RcButton variant="secondary" @click="downloadTemplate">Örnek dosyayı indir</RcButton>

        <input
          ref="fileInput"
          type="file"
          accept=".csv,.xlsx"
          class="sr-only"
          :disabled="uploading"
          @change="onFileSelected"
        >

        <RcButton :loading="uploading" :disabled="uploading" @click="fileInput?.click()">
          Dosya yükle
        </RcButton>
      </div>
    </header>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>
    <RcAlert v-if="uploadError" tone="danger">{{ uploadError }}</RcAlert>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <div v-else-if="batches.length === 0" class="rc-card p-12 text-center">
      <RcFeatureIcon
        class="mx-auto"
        size="lg"
        icon="M4 4h10l6 6v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Zm10 0v6h6M8 14h8m-8 3h5"
      />
      <h2 class="mt-5 text-lg font-medium">Henüz dosya yüklemediniz</h2>
      <p class="mx-auto mt-3 max-w-[54ch] leading-relaxed text-ink-secondary">
        Örnek dosyayı indirip kendi ürünlerinizle doldurabilirsiniz. Türkçe Excel'in
        noktalı virgüllü ve virgüllü ondalıklı biçimi doğrudan desteklenir; sütun
        adlarınızı değiştirmeniz gerekmez.
      </p>
      <RcButton class="mt-7" variant="secondary" @click="downloadTemplate">
        Örnek dosyayı indir
      </RcButton>
    </div>

    <div v-else class="rc-card overflow-hidden">
      <table class="w-full text-sm">
        <thead class="border-b border-line bg-bg-muted text-left">
          <tr>
            <th class="px-5 py-3 font-medium">Dosya</th>
            <th class="px-5 py-3 font-medium">Durum</th>
            <th class="px-5 py-3 font-medium">Sonuç</th>
            <th class="px-5 py-3 font-medium">Tarih</th>
            <th class="px-5 py-3" />
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="batch in batches"
            :key="batch.id"
            class="border-b border-line last:border-0 hover:bg-bg-muted/60"
          >
            <td class="px-5 py-4">
              <p class="font-medium">{{ batch.original_name }}</p>
              <p class="text-xs text-muted">{{ batch.total_rows }} satır</p>
            </td>

            <td class="px-5 py-4">
              <RcStatusPill
                :status="batch.status === 'completed' ? 'approved' : batch.status === 'failed' ? 'rejected' : 'in_review'"
                :label="batch.status_label"
                size="sm"
              />
            </td>

            <td class="px-5 py-4 text-ink-secondary">{{ outcomeOf(batch) }}</td>

            <td class="px-5 py-4 text-xs text-muted">
              {{ batch.created_at ? new Date(batch.created_at).toLocaleString('tr-TR') : '—' }}
            </td>

            <td class="px-5 py-4 text-right">
              <RcButton :to="`/imports/${batch.id}`" size="sm" variant="secondary">
                {{ batch.status === 'completed' || batch.status === 'failed' ? 'Görüntüle' : 'Devam et' }}
              </RcButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
