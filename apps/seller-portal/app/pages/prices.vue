<script setup lang="ts">
import type { PriceHistoryEntry, SellerPriceRow } from '@refconcept/ui/types'

/**
 * Prices, edited in bulk.
 *
 * Changing four hundred prices one row at a time is how a seller ends up with a
 * half-applied campaign when their connection drops, so edits accumulate on screen
 * and go up as a single request. Nothing is sent until the seller says so — an
 * autosaving price grid is a repricing accident waiting for a stray keystroke.
 *
 * Amounts are typed the Turkish way and converted to integer minor units once, on
 * submit, by the shared `useMoney` helper. Nothing here holds a price as a float.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Fiyatlar' })

const api = useApi()

const rows = ref<SellerPriceRow[]>([])
const total = ref(0)
const loading = ref(true)
const loadError = ref<string | null>(null)
const search = ref('')

/** sku_id → what the seller typed, only for rows they actually touched. */
const edits = ref<Record<string, { list: string, sale: string }>>({})

const saving = ref(false)
const saveError = ref<string | null>(null)
const saveMessage = ref<string | null>(null)

const historyFor = ref<SellerPriceRow | null>(null)
const history = ref<PriceHistoryEntry[]>([])

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const query: Record<string, string | number> = { per_page: 50 }
    if (search.value) query.search = search.value

    const response = await api.get<{ data: SellerPriceRow[], meta: { total: number } }>(
      '/api/v1/seller/prices',
      query,
    )

    rows.value = response.data
    total.value = response.meta.total

    // Edits are dropped on reload: they have either been saved, in which case the
    // server's values are now the truth, or the seller changed the filter and the rows
    // they typed into are no longer on screen. Keeping them would silently resubmit
    // stale prices on the next save.
    edits.value = {}
  } catch (error) {
    loadError.value = error instanceof ApiError
      ? (error.status === 403 ? 'Fiyat yönetimi için satıcı hesabınızın onaylanması gerekiyor.' : error.message)
      : 'Fiyatlar yüklenemedi.'
  } finally {
    loading.value = false
  }
}

await load()

let searchTimer: ReturnType<typeof setTimeout> | undefined

watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 350)
})

function editOf(row: SellerPriceRow) {
  edits.value[row.sku_id] ??= {
    list: minorToInput(row.list_price.amount_minor),
    sale: row.sale_price ? minorToInput(row.sale_price.amount_minor) : '',
  }

  return edits.value[row.sku_id]!
}

/** Only rows whose typed value differs from what the server sent. */
const changed = computed(() =>
  rows.value.filter((row) => {
    const edit = edits.value[row.sku_id]

    if (!edit) return false

    const list = inputToMinor(edit.list)
    const sale = edit.sale.trim() === '' ? null : inputToMinor(edit.sale)

    return list !== row.list_price.amount_minor || sale !== (row.sale_price?.amount_minor ?? null)
  }),
)

const invalid = computed(() =>
  changed.value.filter((row) => {
    const edit = edits.value[row.sku_id]!
    const list = inputToMinor(edit.list)
    const sale = edit.sale.trim() === '' ? null : inputToMinor(edit.sale)

    if (list === null) return true
    if (edit.sale.trim() !== '' && sale === null) return true

    return sale !== null && sale > list
  }),
)

async function save() {
  saving.value = true
  saveError.value = null
  saveMessage.value = null

  try {
    const payload = changed.value.map((row) => {
      const edit = edits.value[row.sku_id]!

      return {
        sku_id: row.sku_id,
        list_price_minor: inputToMinor(edit.list),
        sale_price_minor: edit.sale.trim() === '' ? null : inputToMinor(edit.sale),
      }
    })

    const response = await api.post<{ message: string }>('/api/v1/seller/prices/bulk', {
      prices: payload,
    })

    saveMessage.value = response.message
    await load()
  } catch (error) {
    saveError.value = error instanceof ApiError
      ? (error.fieldError('prices') ?? error.message)
      : 'Fiyatlar kaydedilemedi.'
  } finally {
    saving.value = false
  }
}

async function openHistory(row: SellerPriceRow) {
  historyFor.value = row

  try {
    const response = await api.get<{ data: PriceHistoryEntry[] }>(
      `/api/v1/seller/prices/${row.sku_id}/history`,
    )

    history.value = response.data
  } catch {
    history.value = []
  }
}

const sourceLabels: Record<string, string> = {
  sku: 'Ürün fiyatı',
  default_list: 'Varsayılan liste',
  campaign: 'Kampanya',
}

const changeSources: Record<string, string> = {
  manual: 'Elle',
  import: 'Toplu aktarma',
  api: 'API',
  campaign: 'Kampanya',
  system: 'Sistem',
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-medium">Fiyatlar</h1>
        <p class="mt-1.5 max-w-[70ch] text-sm leading-relaxed text-ink-secondary">
          Fiyatları düzenleyip tek seferde kaydedin. Kaydet demeden hiçbir değişiklik
          gönderilmez, ve her değişiklik geçmişe kaydedilir.
        </p>
      </div>

      <RcButton
        :loading="saving"
        :disabled="saving || changed.length === 0 || invalid.length > 0"
        @click="save"
      >
        {{ changed.length > 0 ? `${changed.length} fiyatı kaydet` : 'Kaydet' }}
      </RcButton>
    </header>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>
    <RcAlert v-if="saveError" tone="danger">{{ saveError }}</RcAlert>
    <RcAlert v-if="saveMessage" tone="success">{{ saveMessage }}</RcAlert>

    <RcAlert v-if="invalid.length > 0" tone="warning">
      {{ invalid.length }} satırda geçersiz fiyat var. İndirimli fiyat liste fiyatından
      yüksek olamaz.
    </RcAlert>

    <div class="min-w-[220px] max-w-md">
      <input
        v-model="search"
        type="search"
        placeholder="SKU veya ürün adı ara"
        class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
      >
    </div>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <div v-else-if="rows.length === 0" class="rc-card p-12 text-center">
      <p class="text-sm text-ink-secondary">
        {{ search ? 'Bu aramayla eşleşen satış seçeneği yok.' : 'Henüz satış seçeneği eklemediniz.' }}
      </p>
    </div>

    <div v-else class="rc-card overflow-x-auto">
      <table class="w-full min-w-[760px] text-sm">
        <thead class="border-b border-line bg-bg-muted text-left">
          <tr>
            <th class="px-5 py-3 font-medium">Ürün</th>
            <th class="px-5 py-3 font-medium">Liste fiyatı (₺)</th>
            <th class="px-5 py-3 font-medium">İndirimli (₺)</th>
            <th class="px-5 py-3 font-medium">Geçerli fiyat</th>
            <th class="px-5 py-3" />
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="row in rows"
            :key="row.sku_id"
            class="border-b border-line last:border-0"
          >
            <td class="px-5 py-3.5">
              <p class="font-medium">{{ row.product_name ?? '—' }}</p>
              <p class="text-xs text-muted">
                {{ row.sku }}<span v-if="row.variant_label"> · {{ row.variant_label }}</span>
              </p>
            </td>

            <td class="px-5 py-3.5">
              <input
                v-model="editOf(row).list"
                type="text"
                inputmode="decimal"
                class="w-32 rounded-sm border border-line bg-surface px-3 py-2 text-sm tabular-nums"
                :aria-label="`${row.sku} liste fiyatı`"
              >
            </td>

            <td class="px-5 py-3.5">
              <input
                v-model="editOf(row).sale"
                type="text"
                inputmode="decimal"
                placeholder="—"
                class="w-32 rounded-sm border border-line bg-surface px-3 py-2 text-sm tabular-nums"
                :aria-label="`${row.sku} indirimli fiyatı`"
              >
            </td>

            <td class="px-5 py-3.5">
              <p class="tabular-nums">{{ row.effective_price.formatted }}</p>
              <!-- A campaign list overrides the product form, which is otherwise a
                   mystery when the seller edits a price and nothing changes. -->
              <p v-if="row.price_source !== 'sku'" class="text-xs text-gold">
                {{ sourceLabels[row.price_source] }}
              </p>
            </td>

            <td class="px-5 py-3.5 text-right">
              <button
                type="button"
                class="rounded-sm px-2.5 py-1.5 text-xs text-ink-secondary hover:bg-bg-muted"
                @click="openHistory(row)"
              >
                Geçmiş
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="!loading && rows.length > 0" class="text-xs text-muted">
      {{ total }} kayıttan {{ rows.length }} tanesi gösteriliyor.
    </p>

    <!-- Price history -->
    <div
      v-if="historyFor"
      class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-charcoal/40 p-4 sm:p-8"
      @click.self="historyFor = null"
    >
      <div class="rc-card w-full max-w-lg p-6 sm:p-8">
        <header class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-medium">Fiyat geçmişi</h2>
            <p class="mt-1 text-xs text-muted">{{ historyFor.sku }}</p>
          </div>
          <button
            type="button"
            aria-label="Kapat"
            class="rounded-sm px-2 py-1 text-sm text-muted hover:bg-bg-muted"
            @click="historyFor = null"
          >
            ✕
          </button>
        </header>

        <p v-if="history.length === 0" class="mt-5 text-sm text-ink-secondary">
          Bu satış seçeneği için kayıtlı fiyat değişikliği yok.
        </p>

        <ul v-else class="mt-5 space-y-3">
          <li
            v-for="(entry, index) in history"
            :key="index"
            class="border-b border-line pb-3 text-sm last:border-0"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="text-muted">
                {{ entry.field === 'list_price' ? 'Liste fiyatı' : 'İndirimli fiyat' }}
              </span>
              <span class="text-xs text-muted">{{ changeSources[entry.source] ?? entry.source }}</span>
            </div>

            <p class="mt-1 tabular-nums">
              <s v-if="entry.old_price" class="text-muted">{{ entry.old_price.formatted }}</s>
              <span v-else class="text-muted">Yeni</span>
              <span class="mx-1.5">→</span>
              <span>{{ entry.new_price?.formatted ?? 'Kaldırıldı' }}</span>
              <span
                v-if="entry.change_bps !== null"
                class="ml-2 text-xs"
                :class="entry.change_bps < 0 ? 'text-success-strong' : 'text-danger-strong'"
              >
                {{ entry.change_bps > 0 ? '+' : '' }}%{{ bpsToWholePercent(entry.change_bps) }}
              </span>
            </p>

            <p class="mt-1 text-xs text-muted">
              {{ new Date(entry.changed_at).toLocaleString('tr-TR') }}
              <span v-if="entry.author"> · {{ entry.author }}</span>
            </p>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
