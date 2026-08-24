<script setup lang="ts">
import type { SellerApplication } from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'İnceleme kuyruğu' })

const api = useApi()

const applications = ref<SellerApplication[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const search = ref('')
const statusFilter = ref('')

const statuses = [
  { value: '', label: 'İnceleme kuyruğu' },
  { value: 'draft', label: 'Taslak' },
  { value: 'submitted', label: 'Gönderildi' },
  { value: 'in_review', label: 'İncelemede' },
  { value: 'approved', label: 'Onaylandı' },
  { value: 'rejected', label: 'Reddedildi' },
]

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const query: Record<string, string> = {}
    if (statusFilter.value) query.status = statusFilter.value
    if (search.value) query.search = search.value

    const response = await api.get<{ data: SellerApplication[] }>(
      '/api/v1/admin/seller-applications',
      query,
    )

    applications.value = response.data
  } catch (error) {
    loadError.value = error instanceof ApiError
      ? (error.status === 403 ? 'Bu alana erişim yetkiniz yok.' : error.message)
      : 'Başvurular yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

/** Debounced so typing does not fire a request per keystroke. */
let searchTimer: ReturnType<typeof setTimeout> | undefined

watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 350)
})

watch(statusFilter, load)

function waitingSince(application: SellerApplication): string {
  if (!application.submitted_at) return '—'

  const days = Math.floor(
    (Date.now() - new Date(application.submitted_at).getTime()) / 86_400_000,
  )

  if (days === 0) return 'bugün'

  return `${days} gündür`
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-medium">Satıcı başvuruları</h1>
        <p class="mt-1.5 text-sm text-ink-secondary">
          İnceleme bekleyen başvurular. Her karar gerekçe ister ve denetim kaydına düşer.
        </p>
      </div>
    </header>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <div class="flex flex-wrap gap-3">
      <div class="min-w-[220px] flex-1">
        <input
          v-model="search"
          type="search"
          placeholder="Firma, mağaza veya e-posta ara"
          class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
        >
      </div>

      <select
        v-model="statusFilter"
        class="rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
      >
        <option v-for="option in statuses" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>
    </div>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <div v-else-if="applications.length === 0" class="rc-card p-10 text-center">
      <p class="text-sm text-ink-secondary">Bu filtreyle eşleşen başvuru yok.</p>
    </div>

    <div v-else class="rc-card overflow-hidden">
      <table class="w-full text-sm">
        <thead class="border-b border-line bg-bg-muted text-left">
          <tr>
            <th class="px-5 py-3 font-medium">Firma</th>
            <th class="px-5 py-3 font-medium">İletişim</th>
            <th class="px-5 py-3 font-medium">Durum</th>
            <th class="px-5 py-3 font-medium">Bekleme</th>
            <th class="px-5 py-3" />
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="application in applications"
            :key="application.id"
            class="border-b border-line last:border-0 hover:bg-bg-muted/60"
          >
            <td class="px-5 py-4">
              <p class="font-medium">{{ application.company_name }}</p>
              <p class="text-xs text-muted">{{ application.display_name }}</p>
            </td>
            <td class="px-5 py-4 text-ink-secondary">{{ application.contact_email }}</td>
            <td class="px-5 py-4">
              <span
                class="rounded-pill px-2.5 py-1 text-xs"
                :class="{
                  'bg-warning-subtle text-warning-strong': ['submitted', 'in_review'].includes(application.status),
                  'bg-success-subtle text-success-strong': application.status === 'approved',
                  'bg-danger-subtle text-danger-strong': application.status === 'rejected',
                  'bg-bg-muted text-ink-secondary': ['draft', 'withdrawn'].includes(application.status),
                }"
              >
                {{ application.status_label }}
              </span>
            </td>
            <td class="px-5 py-4 text-ink-secondary">{{ waitingSince(application) }}</td>
            <td class="px-5 py-4 text-right">
              <RcButton :to="`/seller-applications/${application.id}`" size="sm" variant="secondary">
                İncele
              </RcButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
