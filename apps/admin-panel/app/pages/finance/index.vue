<script setup lang="ts">
/**
 * The books, and the payouts that come out of them.
 *
 * "Denk" is the first thing on the page, because if the journal ever stops balancing then
 * nothing else here means anything and the right response is to stop reading and call
 * somebody — not to scroll past it looking for a total.
 *
 * Approving and paying are two buttons, not one. Approving commits money; paying records
 * that a transfer actually left. Collapsing them would mean a mistake in the arithmetic
 * becomes a bank transfer.
 */
import type { FinanceOverview, LedgerEntryRow, SettlementRow } from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Finans' })

const api = useApi()

const overview = ref<FinanceOverview | null>(null)
const settlements = ref<SettlementRow[]>([])
const entries = ref<LedgerEntryRow[]>([])

const loading = ref(true)
const busy = ref(false)
const loadError = ref<string | null>(null)
const banner = ref<{ tone: 'success' | 'danger', text: string } | null>(null)

const filter = ref<'open' | 'paid' | 'cancelled'>('open')

/** The settlement being acted on, and what the operator has typed about it. */
const active = ref<SettlementRow | null>(null)
const payoutReference = ref('')
const cancelReason = ref('')

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const [summary, settlementList, journal] = await Promise.all([
      api.get<{ data: FinanceOverview }>('/api/v1/admin/finance/overview'),
      api.get<{ data: SettlementRow[] }>(
        '/api/v1/admin/finance/settlements',
        filter.value === 'open' ? {} : { status: filter.value },
      ),
      api.get<{ data: LedgerEntryRow[] }>('/api/v1/admin/finance/entries'),
    ])

    overview.value = summary.data
    settlements.value = settlementList.data
    entries.value = journal.data
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Finans bilgileri yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

watch(filter, load)

async function act(operation: () => Promise<unknown>, success: string) {
  busy.value = true
  banner.value = null

  try {
    await operation()
    banner.value = { tone: 'success', text: success }
    active.value = null
    payoutReference.value = ''
    cancelReason.value = ''
    await load()
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'İşlem tamamlanamadı.',
    }
  } finally {
    busy.value = false
  }
}

const build = () => act(
  () => api.post('/api/v1/admin/finance/settlements/build'),
  'Hakediş taslakları hazırlandı.',
)

const approve = (row: SettlementRow) => act(
  () => api.post(`/api/v1/admin/finance/settlements/${row.id}/approve`),
  'Hakediş onaylandı.',
)

const markPaid = () => act(
  () => api.post(`/api/v1/admin/finance/settlements/${active.value?.id}/paid`, {
    payout_reference: payoutReference.value,
  }),
  'Hakediş ödendi olarak işaretlendi.',
)

const cancel = () => act(
  () => api.post(`/api/v1/admin/finance/settlements/${active.value?.id}/cancel`, {
    reason: cancelReason.value,
  }),
  'Hakediş iptal edildi.',
)

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString('tr-TR')
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-baseline justify-between gap-3">
      <div>
        <h1 class="text-xl font-medium">Finans</h1>
        <p class="mt-1 text-sm text-muted">
          Çift taraflı defter, satıcı bakiyeleri ve hakediş ödemeleri.
        </p>
      </div>
      <RcButton :loading="busy" @click="build">Hakediş taslaklarını hazırla</RcButton>
    </header>

    <RcAlert v-if="banner" :tone="banner.tone">{{ banner.text }}</RcAlert>
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <template v-else-if="overview">
      <!--
        First on the page. If the journal stops balancing, nothing else here means
        anything.
      -->
      <RcAlert v-if="!overview.is_balanced" tone="danger">
        <strong>Defter denk değil.</strong> Borç ve alacak toplamları eşleşmiyor; rapor
        almadan önce teknik ekiple görüşün.
      </RcAlert>

      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <!--
          The aggregate first: the per-seller accounts are the record, but "what do we owe
          sellers" is what an operator needs to see next to the cash we are holding.
        -->
        <div class="rounded-sm border border-line-strong bg-surface p-5">
          <p class="text-[11px] tracking-wide text-muted uppercase">Satıcıya borç</p>
          <p class="mt-1 text-xl font-medium tabular-nums">{{ money(overview.sellers_owed_minor) }}</p>
          <p class="mt-1 text-[11px] text-muted">{{ overview.sellers_owed }} satıcının ödemeye hazır bakiyesi var</p>
        </div>

        <div
          v-for="account in overview.accounts"
          :key="account.code"
          class="rounded-sm border border-line bg-surface p-5"
        >
          <p class="text-[11px] tracking-wide text-muted uppercase">{{ account.label }}</p>
          <p class="mt-1 text-xl font-medium tabular-nums">{{ money(account.balance_minor) }}</p>
          <p class="mt-1 font-mono text-[11px] text-muted">{{ account.code }}</p>
        </div>
      </div>

      <section>
        <div class="flex flex-wrap items-baseline justify-between gap-3">
          <h2 class="text-sm font-medium">Hakedişler</h2>

          <div class="flex gap-2">
            <button
              v-for="option in [
                { value: 'open', label: 'Açık' },
                { value: 'paid', label: 'Ödenen' },
                { value: 'cancelled', label: 'İptal' },
              ]"
              :key="option.value"
              type="button"
              class="rounded-pill border px-3 py-1 text-sm"
              :class="filter === option.value ? 'border-ink bg-ink text-surface' : 'border-line hover:bg-bg-muted'"
              @click="filter = option.value as 'open' | 'paid' | 'cancelled'"
            >
              {{ option.label }}
            </button>
          </div>
        </div>

        <p v-if="settlements.length === 0" class="mt-3 rounded-sm border border-line bg-surface p-8 text-center text-sm text-muted">
          Bu listede hakediş yok.
        </p>

        <div v-else class="mt-3 overflow-x-auto rounded-sm border border-line bg-surface">
          <table class="w-full text-sm">
            <thead class="border-b border-line text-left text-xs text-muted uppercase">
              <tr>
                <th class="px-4 py-3">Referans</th>
                <th class="px-4 py-3">Satıcı</th>
                <th class="px-4 py-3">Dönem</th>
                <th class="px-4 py-3 text-right">Brüt</th>
                <th class="px-4 py-3 text-right">Komisyon</th>
                <th class="px-4 py-3 text-right">Net</th>
                <th class="px-4 py-3">Durum</th>
                <th class="px-4 py-3" />
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="row in settlements" :key="row.id">
                <td class="px-4 py-3 font-mono text-xs">{{ row.reference }}</td>
                <td class="px-4 py-3">{{ row.seller_name ?? '—' }}</td>
                <td class="px-4 py-3 text-xs text-muted">
                  {{ when(row.period_start) }} – {{ when(row.period_end) }}
                </td>
                <td class="px-4 py-3 text-right tabular-nums">{{ money(row.gross_minor, row.currency) }}</td>
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
                </td>
                <td class="px-4 py-3 text-right">
                  <!-- Two steps, never one: approving commits money, paying records that
                       it left. -->
                  <RcButton
                    v-if="row.status === 'draft'"
                    size="sm"
                    variant="ghost"
                    :loading="busy"
                    @click="approve(row)"
                  >
                    Onayla
                  </RcButton>
                  <RcButton
                    v-else-if="row.status === 'approved'"
                    size="sm"
                    variant="ghost"
                    @click="active = row"
                  >
                    Ödendi olarak işaretle
                  </RcButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="active" class="rounded-sm border border-line-strong bg-surface p-6">
        <h2 class="text-sm font-medium">
          {{ active.reference }} — {{ money(active.net_minor, active.currency) }}
        </h2>

        <div class="mt-4">
          <label for="payout-reference" class="mb-1.5 block text-sm font-medium">
            Banka referansı
          </label>
          <input
            id="payout-reference"
            v-model="payoutReference"
            type="text"
            maxlength="191"
            placeholder="EFT-2026-00918"
            class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
          >
          <p class="mt-1 text-xs text-muted">
            Transferi gerçekten yaptıktan sonra işaretleyin; bankadaki referansı yazın.
          </p>
        </div>

        <div class="mt-4 flex flex-wrap gap-3">
          <RcButton :loading="busy" :disabled="payoutReference.trim().length < 3" @click="markPaid">
            Ödendi olarak işaretle
          </RcButton>
          <RcButton variant="ghost" :disabled="busy" @click="active = null">Vazgeç</RcButton>
        </div>

        <div class="mt-6 border-t border-line pt-5">
          <label for="cancel-reason" class="mb-1.5 block text-sm font-medium">İptal gerekçesi</label>
          <input
            id="cancel-reason"
            v-model="cancelReason"
            type="text"
            maxlength="300"
            placeholder="Banka bilgisi hatalı"
            class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
          >
          <RcButton
            class="mt-3"
            variant="danger"
            :loading="busy"
            :disabled="cancelReason.trim().length < 5"
            @click="cancel"
          >
            Hakedişi iptal et
          </RcButton>
        </div>
      </section>

      <section v-if="entries.length > 0">
        <h2 class="text-sm font-medium">Yevmiye</h2>

        <ul class="mt-3 space-y-2">
          <li v-for="entry in entries.slice(0, 20)" :key="entry.id" class="rounded-sm border border-line bg-surface p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
              <div>
                <p class="text-sm">{{ entry.description }}</p>
                <p class="mt-0.5 font-mono text-[11px] text-muted">
                  {{ entry.type }} · {{ new Date(entry.posted_at).toLocaleString('tr-TR') }}
                </p>
              </div>
              <p class="text-sm font-medium tabular-nums">{{ money(entry.total_minor, entry.currency) }}</p>
            </div>

            <ul class="mt-3 space-y-1 text-xs">
              <li v-for="(line, index) in entry.lines" :key="index" class="flex justify-between gap-4">
                <span class="font-mono text-muted">{{ line.account }}</span>
                <span class="tabular-nums">
                  <template v-if="line.debit_minor > 0">B {{ money(line.debit_minor, entry.currency) }}</template>
                  <template v-else>A {{ money(line.credit_minor, entry.currency) }}</template>
                </span>
              </li>
            </ul>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
