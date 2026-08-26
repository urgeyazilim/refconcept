<script setup lang="ts">
/**
 * Returns coming back to the seller.
 *
 * Accepting some of a request is the ordinary case — three chairs sent back, one arrived
 * scratched — so the quantities are per line rather than a single yes or no.
 *
 * `Teslim aldım` and `Tamamla` are two buttons, not one, because opening the box is where
 * a return is actually decided and completing one is what releases the money. A single
 * button would make a courier's delivery scan into a refund.
 */
import type { ReturnDetail } from '@refconcept/ui/types'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'İadeler' })

const api = useApi()

const returns = ref<ReturnDetail[]>([])
const loading = ref(true)
const busy = ref(false)
const loadError = ref<string | null>(null)
const banner = ref<{ tone: 'success' | 'danger', text: string } | null>(null)

const scope = ref<'open' | 'completed' | 'rejected'>('open')

/** The request being decided, and the quantities the seller has accepted. */
const active = ref<ReturnDetail | null>(null)
const approved = reactive<Record<string, number>>({})
const note = ref('')

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const response = await api.get<{ data: ReturnDetail[] }>(
      '/api/v1/seller/returns',
      scope.value === 'open' ? {} : { status: scope.value },
    )

    returns.value = response.data
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : 'İadeler yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

watch(scope, load)

function open(row: ReturnDetail) {
  active.value = row
  note.value = ''

  // Defaulted to everything asked for, because accepting in full is the common answer —
  // but every figure is editable, which is what makes a partial decision possible.
  for (const item of row.items) {
    approved[item.id] = item.quantity
  }
}

async function act(operation: () => Promise<unknown>, success: string) {
  busy.value = true
  banner.value = null

  try {
    await operation()
    banner.value = { tone: 'success', text: success }
    active.value = null
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

const accept = () => act(
  () => api.post(`/api/v1/seller/returns/${active.value?.reference}/decision`, {
    accept: true,
    approved,
    note: note.value || undefined,
  }),
  'İade onaylandı.',
)

const reject = () => act(
  () => api.post(`/api/v1/seller/returns/${active.value?.reference}/decision`, {
    accept: false,
    note: note.value,
  }),
  'İade reddedildi.',
)

const advance = (row: ReturnDetail, status: 'received' | 'completed') => act(
  () => api.post(`/api/v1/seller/returns/${row.reference}/status`, { status }),
  status === 'received' ? 'Ürün teslim alındı olarak işaretlendi.' : 'İade tamamlandı.',
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
        <h1 class="text-xl font-medium">İadeler</h1>
        <p class="mt-1 text-sm text-muted">
          Açık bir iade, o siparişin hakedişini bekletir. Sonuçlandırdığınızda serbest kalır.
        </p>
      </div>

      <div class="flex gap-2">
        <button
          v-for="option in [
            { value: 'open', label: 'Bekleyenler' },
            { value: 'completed', label: 'Tamamlanan' },
            { value: 'rejected', label: 'Reddedilen' },
          ]"
          :key="option.value"
          type="button"
          class="rounded-pill border px-3 py-1 text-sm"
          :class="scope === option.value ? 'border-ink bg-ink text-surface' : 'border-line hover:bg-bg-muted'"
          @click="scope = option.value as 'open' | 'completed' | 'rejected'"
        >
          {{ option.label }}
        </button>
      </div>
    </header>

    <RcAlert v-if="banner" :tone="banner.tone">{{ banner.text }}</RcAlert>
    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <p v-else-if="returns.length === 0" class="rounded-sm border border-line bg-surface p-10 text-center text-sm text-muted">
      Bu listede iade yok.
    </p>

    <ul v-else class="space-y-3">
      <li v-for="row in returns" :key="row.id" class="rounded-sm border border-line bg-surface p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
          <div>
            <p class="font-mono text-sm">{{ row.reference }}</p>
            <p class="mt-1 text-xs text-muted">
              {{ row.seller_order_number }} · {{ when(row.created_at) }}
            </p>
          </div>
          <span class="rounded-pill bg-bg-muted px-3 py-1 text-xs">{{ row.status_label }}</span>
        </div>

        <p class="mt-3 text-sm">
          <span class="text-muted">Sebep:</span> {{ row.reason_code }}
          <template v-if="row.reason_note"> — {{ row.reason_note }}</template>
        </p>

        <ul class="mt-3 divide-y divide-line text-sm">
          <li v-for="item in row.items" :key="item.id" class="flex justify-between gap-4 py-2">
            <span>{{ item.product_name }}</span>
            <span class="tabular-nums text-muted">
              {{ item.quantity }} adet · {{ money(item.unit_price_minor, row.currency) }}
            </span>
          </li>
        </ul>

        <div class="mt-4 flex flex-wrap items-center gap-3">
          <RcButton v-if="row.status === 'requested'" size="sm" variant="ghost" @click="open(row)">
            Karar ver
          </RcButton>

          <!-- Two buttons, never one: the box is opened between them. -->
          <RcButton
            v-if="row.status === 'approved' || row.status === 'in_transit'"
            size="sm"
            variant="ghost"
            :loading="busy"
            @click="advance(row, 'received')"
          >
            Teslim aldım
          </RcButton>

          <RcButton
            v-if="row.status === 'received'"
            size="sm"
            :loading="busy"
            @click="advance(row, 'completed')"
          >
            İadeyi tamamla
          </RcButton>

          <span v-if="row.refund" class="text-xs text-muted">
            Ücret iadesi: {{ money(row.refund.amount_minor, row.refund.currency) }}
            ({{ row.refund.status_label }})
          </span>
        </div>
      </li>
    </ul>

    <section v-if="active" class="rounded-sm border border-line-strong bg-surface p-6">
      <h2 class="font-mono text-sm">{{ active.reference }}</h2>

      <p class="mt-2 text-sm text-ink-secondary">
        Kaç adedini kabul ediyorsunuz? Kabul ettiğiniz adet kadar ücret iadesi yapılır.
      </p>

      <ul class="mt-4 divide-y divide-line">
        <li v-for="item in active.items" :key="item.id" class="flex flex-wrap items-center gap-4 py-3">
          <div class="min-w-0 flex-1">
            <p class="text-sm">{{ item.product_name }}</p>
            <p class="text-xs text-muted">
              Talep: {{ item.quantity }} adet · {{ money(item.unit_price_minor, active.currency) }}
            </p>
          </div>

          <div class="flex items-center gap-2">
            <label :for="`approve-${item.id}`" class="text-xs text-muted">Kabul</label>
            <input
              :id="`approve-${item.id}`"
              v-model.number="approved[item.id]"
              type="number"
              min="0"
              :max="item.quantity"
              class="w-20 rounded-sm border border-line bg-surface px-2 py-1 text-sm tabular-nums"
            >
          </div>
        </li>
      </ul>

      <div class="mt-4">
        <label for="decision-note" class="mb-1.5 block text-sm font-medium">Not</label>
        <input
          id="decision-note"
          v-model="note"
          type="text"
          maxlength="500"
          placeholder="Reddediyorsanız gerekçe zorunlu"
          class="w-full rounded-sm border border-line bg-surface px-3 py-2 text-sm"
        >
      </div>

      <div class="mt-5 flex flex-wrap gap-3">
        <RcButton :loading="busy" @click="accept">İadeyi onayla</RcButton>
        <RcButton variant="danger" :loading="busy" :disabled="note.trim().length < 3" @click="reject">
          Reddet
        </RcButton>
        <RcButton variant="ghost" :disabled="busy" @click="active = null">Vazgeç</RcButton>
      </div>
    </section>
  </div>
</template>
