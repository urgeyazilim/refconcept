<script setup lang="ts">
import type { StockMovementEntry, StockRow } from '@refconcept/ui/types'

/**
 * Stock, as a seller thinks about it.
 *
 * Three numbers rather than one, because "we have twelve" is not a fact a seller can
 * act on: nine may already be promised to customers. `sellable` is what can still be
 * sold, and it is the column the catalogue reads.
 *
 * Corrections are a *count*, not a delta, wherever the seller is telling us what is
 * physically there. A delta is what an arriving delivery is; a stocktake is what a
 * person with a clipboard produces, and conflating them double-counts on the second
 * submission.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Stok' })

const api = useApi()

const rows = ref<StockRow[]>([])
const total = ref(0)
const loading = ref(true)
const loadError = ref<string | null>(null)
const search = ref('')
const onlyAttention = ref(false)

const editing = ref<StockRow | null>(null)
const movements = ref<StockMovementEntry[]>([])
const form = reactive({ mode: 'count' as 'count' | 'delta', counted: 0, delta: 0, reason: '' })
const saving = ref(false)
const formError = ref<string | null>(null)

async function load() {
  loading.value = true
  loadError.value = null

  try {
    const query: Record<string, string | number> = { per_page: 50 }

    if (search.value) query.search = search.value
    if (onlyAttention.value) query.needs_attention = 1

    const response = await api.get<{ data: StockRow[], meta: { total: number } }>(
      '/api/v1/seller/stock',
      query,
    )

    rows.value = response.data
    total.value = response.meta.total
  } catch (error) {
    loadError.value = error instanceof ApiError
      ? (error.status === 403 ? 'Stok yönetimi için satıcı hesabınızın onaylanması gerekiyor.' : error.message)
      : 'Stok yüklenemedi.'
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

watch(onlyAttention, load)

async function openRow(row: StockRow) {
  editing.value = row
  formError.value = null

  Object.assign(form, { mode: 'count', counted: row.on_hand, delta: 0, reason: '' })

  try {
    const response = await api.get<{ data: StockMovementEntry[] }>(
      `/api/v1/seller/stock/${row.id}/movements`,
    )

    movements.value = response.data
  } catch {
    movements.value = []
  }
}

function close() {
  editing.value = null
  movements.value = []
}

async function submit() {
  if (!editing.value?.sku.id) return

  saving.value = true
  formError.value = null

  try {
    const path = form.mode === 'count' ? 'stocktake' : 'adjust'

    const body: Record<string, unknown> = {
      sku_id: editing.value.sku.id,
      location_id: editing.value.location.id,
      reason: form.reason || null,
    }

    if (form.mode === 'count') body.counted = Number(form.counted)
    else {
      body.delta = Number(form.delta)
      body.type = Number(form.delta) > 0 ? 'receipt' : 'adjustment'
    }

    await api.post(`/api/v1/seller/stock/${path}`, body)

    close()
    await load()
  } catch (error) {
    formError.value = error instanceof ApiError
      ? (error.fieldError('counted') ?? error.fieldError('delta') ?? error.fieldError('reason') ?? error.message)
      : 'Stok güncellenemedi.'
  } finally {
    saving.value = false
  }
}

const attentionCount = computed(() => rows.value.filter(row => row.needs_attention).length)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-medium">Stok</h1>
      <p class="mt-1.5 max-w-[72ch] text-sm leading-relaxed text-ink-secondary">
        <span class="text-ink">Satılabilir</span>, müşteriye söz verebileceğiniz adettir:
        elinizdeki adetten, sipariş için ayrılmış olanlar düşülür. Katalogda görünen
        rakam budur.
      </p>
    </header>

    <RcAlert v-if="loadError" tone="danger">{{ loadError }}</RcAlert>

    <RcAlert v-else-if="attentionCount > 0" tone="warning">
      {{ attentionCount }} ürün kritik stok seviyesinde.
    </RcAlert>

    <div class="flex flex-wrap items-center gap-3">
      <div class="min-w-[220px] flex-1">
        <input
          v-model="search"
          type="search"
          placeholder="SKU veya ürün adı ara"
          class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
        >
      </div>

      <label class="flex items-center gap-2.5 text-sm text-ink-secondary">
        <input v-model="onlyAttention" type="checkbox" class="size-4">
        Yalnızca kritik seviye
      </label>
    </div>

    <p v-if="loading" class="text-sm text-muted">Yükleniyor…</p>

    <div v-else-if="rows.length === 0" class="rc-card p-12 text-center">
      <p class="text-sm text-ink-secondary">
        {{ search || onlyAttention ? 'Bu filtreyle eşleşen kayıt yok.' : 'Henüz stok kaydı yok. Bir satış seçeneği eklediğinizde burada görünür.' }}
      </p>
    </div>

    <div v-else class="rc-card overflow-x-auto">
      <table class="w-full min-w-[720px] text-sm">
        <thead class="border-b border-line bg-bg-muted text-left">
          <tr>
            <th class="px-5 py-3 font-medium">Ürün</th>
            <th class="px-5 py-3 font-medium">Depo</th>
            <th class="px-5 py-3 text-right font-medium">Elde</th>
            <th class="px-5 py-3 text-right font-medium">Ayrılmış</th>
            <th class="px-5 py-3 text-right font-medium">Satılabilir</th>
            <th class="px-5 py-3" />
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="row in rows"
            :key="row.id"
            class="border-b border-line last:border-0 hover:bg-bg-muted/60"
            :class="row.needs_attention ? 'bg-warning-subtle/40' : ''"
          >
            <td class="px-5 py-4">
              <p class="font-medium">{{ row.sku.product_name ?? '—' }}</p>
              <p class="text-xs text-muted">
                {{ row.sku.code }}<span v-if="row.sku.variant_label"> · {{ row.sku.variant_label }}</span>
              </p>
            </td>

            <td class="px-5 py-4 text-ink-secondary">{{ row.location.name ?? '—' }}</td>
            <td class="px-5 py-4 text-right tabular-nums">{{ row.on_hand }}</td>
            <td class="px-5 py-4 text-right tabular-nums text-muted">{{ row.reserved }}</td>
            <td
              class="px-5 py-4 text-right font-medium tabular-nums"
              :class="row.sellable === 0 ? 'text-danger-strong' : ''"
            >
              {{ row.sellable }}
            </td>

            <td class="px-5 py-4 text-right">
              <RcButton size="sm" variant="secondary" @click="openRow(row)">Düzenle</RcButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="!loading && rows.length > 0" class="text-xs text-muted">
      {{ total }} kayıttan {{ rows.length }} tanesi gösteriliyor.
    </p>

    <!-- Adjust one row -->
    <div
      v-if="editing"
      class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-charcoal/40 p-4 sm:p-8"
      @click.self="close"
    >
      <div class="rc-card w-full max-w-lg p-6 sm:p-8">
        <header class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-medium">{{ editing.sku.product_name }}</h2>
            <p class="mt-1 text-xs text-muted">{{ editing.sku.code }} · {{ editing.location.name }}</p>
          </div>
          <button
            type="button"
            aria-label="Kapat"
            class="rounded-sm px-2 py-1 text-sm text-muted hover:bg-bg-muted"
            @click="close"
          >
            ✕
          </button>
        </header>

        <RcAlert v-if="formError" tone="danger" class="mt-5">{{ formError }}</RcAlert>

        <div class="mt-5 grid grid-cols-3 gap-3 text-center">
          <div class="rounded-md bg-bg-muted p-3">
            <p class="text-xs text-muted">Elde</p>
            <p class="mt-1 text-lg font-medium tabular-nums">{{ editing.on_hand }}</p>
          </div>
          <div class="rounded-md bg-bg-muted p-3">
            <p class="text-xs text-muted">Ayrılmış</p>
            <p class="mt-1 text-lg font-medium tabular-nums">{{ editing.reserved }}</p>
          </div>
          <div class="rounded-md bg-bg-muted p-3">
            <p class="text-xs text-muted">Satılabilir</p>
            <p class="mt-1 text-lg font-medium tabular-nums">{{ editing.sellable }}</p>
          </div>
        </div>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
          <div class="flex gap-1.5">
            <button
              v-for="option in [
                { value: 'count', label: 'Sayım sonucu' },
                { value: 'delta', label: 'Giriş / çıkış' },
              ]"
              :key="option.value"
              type="button"
              class="flex-1 rounded-sm border px-3 py-2 text-sm transition-colors"
              :class="form.mode === option.value
                ? 'border-charcoal bg-charcoal text-white'
                : 'border-line text-ink-secondary hover:bg-bg-muted'"
              @click="form.mode = option.value as 'count' | 'delta'"
            >
              {{ option.label }}
            </button>
          </div>

          <RcField
            v-if="form.mode === 'count'"
            v-model="form.counted"
            label="Sayılan adet"
            name="counted"
            type="number"
            hint="Fiziksel olarak kaç adet olduğunu yazın; kayıt buna göre düzeltilir."
            required
          />

          <RcField
            v-else
            v-model="form.delta"
            label="Değişim"
            name="delta"
            type="number"
            hint="Gelen mal için pozitif, kayıp veya hasar için negatif yazın."
            required
          />

          <div>
            <label for="reason" class="mb-1.5 block text-sm font-medium">Açıklama</label>
            <input
              id="reason"
              v-model="form.reason"
              type="text"
              placeholder="Örn. 12 Mart sevkiyatı, kırık ürün, yıllık sayım"
              class="w-full rounded-sm border border-line bg-surface px-4 py-2.5 text-sm"
            >
            <p class="mt-1.5 text-xs text-muted">
              Açıklamasız bir düzeltme, aylar sonra hatadan ayırt edilemez.
            </p>
          </div>

          <div class="flex items-center gap-3">
            <RcButton type="submit" :loading="saving" :disabled="saving">Kaydet</RcButton>
            <RcButton variant="ghost" @click="close">Vazgeç</RcButton>
          </div>
        </form>

        <section v-if="movements.length > 0" class="mt-8 border-t border-line pt-6">
          <h3 class="text-sm font-medium">Hareketler</h3>
          <ul class="mt-3 space-y-2.5">
            <li
              v-for="movement in movements.slice(0, 12)"
              :key="movement.id"
              class="flex items-start justify-between gap-4 border-b border-line pb-2.5 text-sm last:border-0"
            >
              <div class="min-w-0">
                <p>{{ movement.type_label }}</p>
                <p v-if="movement.reason" class="truncate text-xs text-muted">{{ movement.reason }}</p>
                <p class="text-xs text-muted">
                  {{ new Date(movement.created_at).toLocaleString('tr-TR') }}
                  <span v-if="movement.author"> · {{ movement.author }}</span>
                </p>
              </div>
              <div class="shrink-0 text-right tabular-nums">
                <p :class="movement.quantity > 0 ? 'text-success-strong' : 'text-danger-strong'">
                  {{ movement.quantity > 0 ? '+' : '' }}{{ movement.quantity }}
                </p>
                <p class="text-xs text-muted">→ {{ movement.on_hand_after }}</p>
              </div>
            </li>
          </ul>
        </section>
      </div>
    </div>
  </div>
</template>
