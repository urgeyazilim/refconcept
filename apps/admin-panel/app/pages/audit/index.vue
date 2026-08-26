<script setup lang="ts">
/**
 * The audit trail, and the permission matrix beside it.
 *
 * The trail answers "who did this and why"; the matrix answers "who *could* have". Both
 * on one screen is what turns a list of rows into something a person can reason with —
 * an entry with no matching permission is either a bug or an incident, and you cannot see
 * that from the log alone.
 *
 * The action filter is a prefix, so "payments." finds everything financial without an
 * operator having to know the leaf names.
 */
import type { AdminAuditRow, AdminPermissionMatrix } from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Denetim kaydı' })

const api = useApi()

const entries = ref<AdminAuditRow[]>([])
const matrix = ref<AdminPermissionMatrix | null>(null)

const action = ref('')
const days = ref(30)
const loading = ref(true)
const loadError = ref<string | null>(null)
const expanded = ref<string | null>(null)

/** The prefixes worth one click, in the order an incident is usually worked. */
const shortcuts = [
  { value: '', label: 'Tümü' },
  { value: 'payments.', label: 'Ödemeler' },
  { value: 'finance.', label: 'Finans' },
  { value: 'credit.', label: 'Krediler' },
  { value: 'fulfilment.', label: 'İade/kargo' },
  { value: 'sellers.', label: 'Satıcılar' },
  { value: 'system.', label: 'Sistem' },
]

async function loadEntries() {
  loading.value = true
  loadError.value = null

  try {
    const params: Record<string, string | number> = { days: days.value }

    if (action.value !== '') {
      params.action = action.value
    }

    const response = await api.get<{ data: AdminAuditRow[] }>('/api/v1/admin/audit', params)

    entries.value = response.data
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Denetim kaydı yüklenemedi.'
  } finally {
    loading.value = false
  }
}

async function loadMatrix() {
  try {
    const response = await api.get<{ data: AdminPermissionMatrix }>('/api/v1/admin/audit/matrix')

    matrix.value = response.data
  } catch {
    // The matrix is context, not the page. Losing it should not blank the trail.
    matrix.value = null
  }
}

await Promise.all([loadEntries(), loadMatrix()])

watch([action, days], loadEntries)

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString('tr-TR')
}

function toggle(id: string) {
  expanded.value = expanded.value === id ? null : id
}

const granted = computed(() => (matrix.value?.permissions ?? []).filter(row => row.granted))
const withheld = computed(() => (matrix.value?.permissions ?? []).filter(row => !row.granted))
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-xl font-medium">Denetim kaydı</h1>
      <p class="mt-1 text-sm text-muted">
        Para taşıyan, mal serbest bırakan ve yetki değiştiren her işlem burada.
      </p>
    </header>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <!--
      Should never appear. The suite fails on the same condition, so a non-empty list here
      means an endpoint reached production without a decision about who may call it.
    -->
    <RcAlert v-if="matrix && matrix.uncovered_routes.length > 0" tone="danger">
      <strong>Yetki tanımı olmayan uç var.</strong>
      {{ matrix.uncovered_routes.join(', ') }}
    </RcAlert>

    <div class="flex flex-wrap items-center gap-2">
      <button
        v-for="option in shortcuts"
        :key="option.value"
        type="button"
        class="rounded-pill border px-3 py-1 text-sm"
        :class="action === option.value ? 'border-ink bg-ink text-surface' : 'border-line hover:bg-bg-muted'"
        :data-testid="'audit-filter-' + (option.value === '' ? 'all' : option.value.replace('.', ''))"
        @click="action = option.value"
      >
        {{ option.label }}
      </button>

      <span class="ml-auto flex gap-2">
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
      </span>
    </div>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <p
      v-else-if="entries.length === 0"
      class="rounded-sm border border-line bg-surface p-8 text-center text-sm text-muted"
      data-testid="audit-empty"
    >
      Bu dönemde kayıt yok.
    </p>

    <div v-else class="overflow-x-auto rounded-sm border border-line bg-surface">
      <table class="w-full text-sm">
        <thead class="border-b border-line text-left text-xs text-muted uppercase">
          <tr>
            <th class="px-4 py-3">Zaman</th>
            <th class="px-4 py-3">İşlem</th>
            <th class="px-4 py-3">Kim</th>
            <th class="px-4 py-3">Konu</th>
            <th class="px-4 py-3">Gerekçe</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="entry in entries" :key="entry.id">
            <tr
              class="cursor-pointer border-b border-line last:border-0 hover:bg-bg-muted"
              data-testid="audit-row"
              @click="toggle(entry.id)"
            >
              <td class="px-4 py-3 text-xs whitespace-nowrap text-muted">{{ when(entry.created_at) }}</td>
              <td class="px-4 py-3 font-mono text-xs">{{ entry.action }}</td>
              <td class="px-4 py-3">{{ entry.actor ?? entry.actor_type ?? 'sistem' }}</td>
              <td class="px-4 py-3 text-xs">{{ entry.subject_type }}</td>
              <td class="px-4 py-3 text-ink-secondary">{{ entry.reason ?? '—' }}</td>
            </tr>

            <!--
              The before/after and the context, on demand. Folded away by default because
              a wall of JSON on every row makes the sequence of actions unreadable, and
              the sequence is what an investigation follows first.
            -->
            <tr v-if="expanded === entry.id" class="border-b border-line bg-bg-muted last:border-0">
              <td colspan="5" class="px-4 py-3">
                <pre class="overflow-x-auto text-[11px] leading-relaxed">{{
                  JSON.stringify({ changes: entry.changes, context: entry.context, subject_id: entry.subject_id }, null, 2)
                }}</pre>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <section v-if="matrix" aria-label="Yetkileriniz">
      <h2 class="text-sm font-medium">Yetkileriniz</h2>
      <p class="mt-1 text-sm text-muted">
        Basamadığınız bir düğmenin nedenini 403 ekranından değil buradan öğrenin.
      </p>

      <div class="mt-3 grid gap-3 lg:grid-cols-2">
        <div class="rounded-sm border border-line bg-surface p-5">
          <p class="text-[11px] tracking-wide text-muted uppercase">Var ({{ granted.length }})</p>
          <ul class="mt-2 space-y-1.5">
            <li v-for="row in granted" :key="row.value" class="text-sm">
              {{ row.description }}
              <span class="ml-1 font-mono text-[11px] text-muted">{{ row.value }}</span>
            </li>
          </ul>
        </div>

        <div class="rounded-sm border border-line bg-surface p-5">
          <p class="text-[11px] tracking-wide text-muted uppercase">Yok ({{ withheld.length }})</p>
          <ul class="mt-2 space-y-1.5">
            <li v-for="row in withheld" :key="row.value" class="text-sm text-muted">
              {{ row.description }}
              <span class="ml-1 font-mono text-[11px]">{{ row.value }}</span>
            </li>
          </ul>
        </div>
      </div>
    </section>
  </div>
</template>
