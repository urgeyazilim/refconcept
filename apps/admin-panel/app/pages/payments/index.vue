<script setup lang="ts">
/**
 * Finance: the transfers waiting to be matched against a statement.
 *
 * The screen is built around the one decision it exists for, and around not making that
 * decision carelessly. The expected figure is on every row, the received figure has to be
 * typed rather than defaulted, and the difference is worked out and shown *before* the
 * confirm button does anything — because an operator at the end of a long day will
 * otherwise accept the number that is already in the box.
 */
import type { BankAccountOption, BankTransferRow } from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Ödemeler' })

const api = useApi()

const transfers = ref<BankTransferRow[]>([])
const accounts = ref<BankAccountOption[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const banner = ref<{ tone: 'success' | 'danger', text: string } | null>(null)

const filter = ref<'open' | 'confirmed' | 'short_paid' | 'rejected' | 'expired'>('open')

const filters = [
  { value: 'open', label: 'Bekleyenler' },
  { value: 'short_paid', label: 'Eksik ödeme' },
  { value: 'confirmed', label: 'Onaylanan' },
  { value: 'rejected', label: 'Reddedilen' },
  { value: 'expired', label: 'Süresi dolan' },
] as const

/** The row being decided, and what the operator has typed about it. */
const active = ref<BankTransferRow | null>(null)
const receivedInput = ref('')
const valueDate = ref(new Date().toISOString().slice(0, 10))
const note = ref('')
const rejectReason = ref('')
const busy = ref(false)

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const query = filter.value === 'open' ? {} : { status: filter.value }

    const [queue, accountList] = await Promise.all([
      api.get<{ data: BankTransferRow[] }>('/api/v1/admin/payments/transfers', query),
      api.get<{ data: BankAccountOption[] }>('/api/v1/admin/payments/bank-accounts'),
    ])

    transfers.value = queue.data
    accounts.value = accountList.data
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'Ödemeler yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

watch(filter, load)

function open(row: BankTransferRow) {
  active.value = row
  // Deliberately blank rather than pre-filled with the expected figure. A number already
  // in the box is a number that gets accepted without being read.
  receivedInput.value = ''
  note.value = ''
  rejectReason.value = ''
  banner.value = null
}

/** What was typed, in minor units. */
const receivedMinor = computed(() => {
  const parsed = Number.parseFloat(receivedInput.value.replace(',', '.'))

  return Number.isFinite(parsed) ? Math.round(parsed * 100) : null
})

const difference = computed(() => {
  if (active.value === null || receivedMinor.value === null) return null

  return receivedMinor.value - active.value.expected_minor
})

async function confirm() {
  if (active.value === null || receivedMinor.value === null) return

  busy.value = true

  try {
    await api.post(`/api/v1/admin/payments/transfers/${active.value.id}/confirm`, {
      received_minor: receivedMinor.value,
      value_date: valueDate.value,
      note: note.value || undefined,
    })

    banner.value = { tone: 'success', text: 'Havale sonuçlandırıldı.' }
    active.value = null
    await load()
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'Havale onaylanamadı.',
    }
  } finally {
    busy.value = false
  }
}

async function reject() {
  if (active.value === null) return

  busy.value = true

  try {
    await api.post(`/api/v1/admin/payments/transfers/${active.value.id}/reject`, {
      reason: rejectReason.value,
    })

    banner.value = { tone: 'success', text: 'Havale reddedildi.' }
    active.value = null
    await load()
  } catch (error) {
    banner.value = {
      tone: 'danger',
      text: error instanceof ApiError ? error.message : 'Havale reddedilemedi.',
    }
  } finally {
    busy.value = false
  }
}

function money(minor: number, currency = 'TRY'): string {
  return new Intl.NumberFormat('tr-TR', { style: 'currency', currency }).format(minor / 100)
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-xl font-medium">Ödemeler</h1>
      <p class="mt-1 text-sm text-muted">
        Havale/EFT kayıtları. Onaylamak parayı serbest bırakır ve geri alınamaz.
      </p>
    </header>

    <RcAlert v-if="banner" :tone="banner.tone">{{ banner.text }}</RcAlert>
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <nav class="flex flex-wrap gap-2">
      <button
        v-for="option in filters"
        :key="option.value"
        type="button"
        class="rounded-pill border px-3 py-1 text-sm"
        :class="filter === option.value ? 'border-ink bg-ink text-surface' : 'border-line hover:bg-bg-muted'"
        @click="filter = option.value"
      >
        {{ option.label }}
      </button>
    </nav>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <p v-else-if="transfers.length === 0" class="rounded-sm border border-line bg-surface p-8 text-center text-sm text-muted">
      Bu listede kayıt yok.
    </p>

    <div v-else class="overflow-x-auto rounded-sm border border-line bg-surface">
      <table class="w-full text-sm">
        <thead class="border-b border-line text-left text-xs text-muted uppercase">
          <tr>
            <th class="px-4 py-3">Referans</th>
            <th class="px-4 py-3">Müşteri</th>
            <th class="px-4 py-3 text-right">Beklenen</th>
            <th class="px-4 py-3 text-right">Gelen</th>
            <th class="px-4 py-3">Durum</th>
            <th class="px-4 py-3">Açıldı</th>
            <th class="px-4 py-3" />
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-for="row in transfers" :key="row.id">
            <td class="px-4 py-3 font-mono text-xs">{{ row.reference }}</td>
            <td class="px-4 py-3">{{ row.customer_email ?? '—' }}</td>
            <td class="px-4 py-3 text-right tabular-nums">{{ money(row.expected_minor, row.currency) }}</td>
            <td class="px-4 py-3 text-right tabular-nums">
              {{ row.received_minor === null ? '—' : money(row.received_minor, row.currency) }}
            </td>
            <td class="px-4 py-3">
              <span
                class="rounded-pill px-2 py-0.5 text-xs"
                :class="{
                  'bg-success-subtle text-success-strong': row.status === 'confirmed' || row.status === 'over_paid',
                  'bg-warning-subtle text-warning-strong': row.status === 'short_paid',
                  'bg-bg-muted text-ink-secondary': !['confirmed', 'over_paid', 'short_paid'].includes(row.status),
                }"
              >
                {{ row.status_label }}
              </span>
              <span v-if="row.receipt_count > 0" class="ml-2 text-xs text-muted">
                {{ row.receipt_count }} dekont
              </span>
            </td>
            <td class="px-4 py-3 text-xs text-muted">
              {{ row.created_at ? new Date(row.created_at).toLocaleString('tr-TR') : '—' }}
            </td>
            <td class="px-4 py-3 text-right">
              <RcButton v-if="row.is_decidable" size="sm" variant="ghost" @click="open(row)">
                Sonuçlandır
              </RcButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- The decision, with the arithmetic done in front of the operator. -->
    <section v-if="active" class="rounded-sm border border-line-strong bg-surface p-6">
      <h2 class="text-sm font-medium">
        {{ active.reference }} — {{ money(active.expected_minor, active.currency) }} bekleniyor
      </h2>

      <div class="mt-5 grid gap-4 sm:grid-cols-2">
        <div>
          <label for="received" class="mb-1.5 block text-sm font-medium">Ekstredeki tutar</label>
          <input
            id="received"
            v-model="receivedInput"
            type="text"
            inputmode="decimal"
            placeholder="0,00"
            class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm tabular-nums"
          >
        </div>

        <div>
          <label for="value-date" class="mb-1.5 block text-sm font-medium">Valör tarihi</label>
          <input
            id="value-date"
            v-model="valueDate"
            type="date"
            class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
          >
        </div>
      </div>

      <!--
        Shown before the button does anything. An operator who can see "148,50 ₺ eksik"
        cannot later say they did not notice.
      -->
      <p
        v-if="difference !== null && difference !== 0"
        class="mt-4 rounded-sm px-3 py-2 text-sm"
        :class="difference < 0 ? 'bg-warning-subtle text-warning-strong' : 'bg-info-subtle text-info-strong'"
      >
        <template v-if="difference < 0">
          {{ money(-difference, active.currency) }} eksik. Onaylarsanız sipariş açık kalır ve
          müşteriden fark istenir.
        </template>
        <template v-else>
          {{ money(difference, active.currency) }} fazla. Onaylarsanız sipariş serbest kalır
          ve fark müşteriye iade edilmek üzere kaydedilir.
        </template>
      </p>

      <div class="mt-4">
        <label for="note" class="mb-1.5 block text-sm font-medium">Not</label>
        <input
          id="note"
          v-model="note"
          type="text"
          maxlength="300"
          class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
        >
      </div>

      <div class="mt-5 flex flex-wrap gap-3">
        <RcButton :loading="busy" :disabled="receivedMinor === null || receivedMinor <= 0" @click="confirm">
          Ödemeyi kaydet
        </RcButton>
        <RcButton variant="ghost" :disabled="busy" @click="active = null">Vazgeç</RcButton>
      </div>

      <div class="mt-6 border-t border-line pt-5">
        <label for="reject-reason" class="mb-1.5 block text-sm font-medium">
          Reddetme gerekçesi
        </label>
        <input
          id="reject-reason"
          v-model="rejectReason"
          type="text"
          maxlength="300"
          placeholder="Ekstrede eşleşen kayıt yok"
          class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
        >
        <RcButton
          class="mt-3"
          variant="danger"
          :loading="busy"
          :disabled="rejectReason.trim().length < 5"
          @click="reject"
        >
          Havaleyi reddet
        </RcButton>
      </div>
    </section>

    <section v-if="accounts.length > 0">
      <h2 class="text-sm font-medium">Tahsilat hesapları</h2>
      <ul class="mt-3 grid gap-3 sm:grid-cols-2">
        <li v-for="account in accounts" :key="account.id" class="rounded-sm border border-line bg-surface p-4 text-sm">
          <p class="font-medium">{{ account.bank_name }}</p>
          <p class="mt-1 font-mono text-xs tabular-nums">{{ account.iban }}</p>
          <p class="mt-1 text-xs text-muted">{{ account.account_holder }}</p>
        </li>
      </ul>
    </section>
  </div>
</template>
